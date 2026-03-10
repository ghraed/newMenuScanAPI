<?php

namespace App\Services;

use App\Models\ScanImage;
use App\Support\ScanObjectKeys;
use Closure;
use RuntimeException;
use SplQueue;
use Symfony\Component\Process\Process;
use Throwable;

class MaskService
{
    public function __construct(
        private readonly ObjectStorageService $objectStorage,
    ) {
    }

    /**
     * @return array{key: string, local_path: string}
     */
    public function generateRgba(ScanImage $image, array $options = []): array
    {
        $scanId = (string) $image->scan_id;
        $slot = (int) $image->slot;
        $mode = $options['mode'] ?? 'final';
        $selection = $options['selection'] ?? null;
        $workdir = rtrim((string) ($options['workdir'] ?? ''), DIRECTORY_SEPARATOR);
        $outputKey = (string) ($options['output_key'] ?? $this->resolveOutputKey($scanId, $slot, $mode));

        if ($workdir === '') {
            throw new RuntimeException('rembg failed: workdir missing');
        }

        $inputPath = (string) ($options['input_path'] ?? $this->downloadSourceImage($image, $workdir));
        $outputPath = (string) ($options['output_path'] ?? "{$workdir}/processed/{$slot}.png");
        $outputDir = dirname($outputPath);

        if (! is_file($inputPath)) {
            throw new RuntimeException("rembg input missing for slot {$slot}: {$inputPath}");
        }

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException("failed to create rgba directory for slot {$slot}");
        }

        $prepared = $this->prepareInputImage($slot, $inputPath, "{$workdir}/tmp", $selection, $mode);
        $preparedInputPath = $prepared['path'];
        $selectionContext = $prepared['selectionContext'];
        $binary = (string) env('REMBG_BIN', 'rembg');
        $process = new Process([$binary, 'i', $preparedInputPath, $outputPath]);
        $process->setTimeout(600);
        /** @var (Closure(): bool)|null $shouldCancel */
        $shouldCancel = $options['should_cancel'] ?? null;

        try {
            $this->runProcess($process, $shouldCancel);
        } catch (Throwable $e) {
            throw new RuntimeException(
                $this->formatFailureMessage($slot, $process->getErrorOutput() ?: $e->getMessage()),
                previous: $e
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                $this->formatFailureMessage($slot, $process->getErrorOutput() ?: $process->getOutput())
            );
        }

        if (! is_file($outputPath)) {
            throw new RuntimeException("rembg failed for slot {$slot}: output missing");
        }

        if ($selectionContext !== null) {
            $this->refineMaskToSelection($outputPath, $selectionContext);
        }

        if ($preparedInputPath !== $inputPath && is_file($preparedInputPath)) {
            @unlink($preparedInputPath);
        }

        $this->objectStorage->uploadFile($outputKey, $outputPath, [
            'ContentType' => 'image/png',
        ]);

        return [
            'key' => $outputKey,
            'local_path' => $outputPath,
        ];
    }

    private function runProcess(Process $process, ?Closure $shouldCancel = null): void
    {
        $process->start();

        while ($process->isRunning()) {
            if ($shouldCancel && $shouldCancel()) {
                $process->stop(1, 9);
                throw new RuntimeException('Job canceled.');
            }

            usleep(200000);
        }

        $process->wait();
    }

    private function resolveOutputKey(string $scanId, int $slot, string $mode): string
    {
        return $mode === 'preview'
            ? ScanObjectKeys::processedPreviewForSlot($scanId, $slot)
            : ScanObjectKeys::processedForSlot($scanId, $slot);
    }

    private function prepareInputImage(
        int $slot,
        string $inputPath,
        string $tempDir,
        mixed $selection,
        string $mode
    ): array {
        if (! function_exists('imagecreatefromjpeg')) {
            return [
                'path' => $inputPath,
                'selectionContext' => null,
            ];
        }

        $originalImage = @imagecreatefromjpeg($inputPath);
        if (! $originalImage) {
            return [
                'path' => $inputPath,
                'selectionContext' => null,
            ];
        }

        $originalWidth = imagesx($originalImage);
        $originalHeight = imagesy($originalImage);
        $cropWindow = $this->resolveCropWindow($selection, $originalWidth, $originalHeight, $mode);
        $croppedImage = null;
        $workingImage = $originalImage;

        if ($cropWindow !== null) {
            $cropped = imagecrop($originalImage, $cropWindow);
            if ($cropped instanceof \GdImage) {
                $croppedImage = $cropped;
                $workingImage = $croppedImage;
            }
        }

        $maxDimension = $mode === 'preview' ? 640 : 1800;
        $resizedImage = $this->resizeIfNeeded($workingImage, $maxDimension);
        $preparedWidth = imagesx($resizedImage);
        $preparedHeight = imagesy($resizedImage);
        $selectionContext = $this->buildSelectionContext(
            $selection,
            $mode,
            $originalWidth,
            $originalHeight,
            $cropWindow,
            $preparedWidth,
            $preparedHeight,
        );

        if (! is_dir($tempDir) && ! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw new RuntimeException("failed to create temp directory for slot {$slot}");
        }

        $tempPath = "{$tempDir}/slot-{$slot}-{$mode}.jpg";
        if (! imagejpeg($resizedImage, $tempPath, $mode === 'preview' ? 82 : 92)) {
            if ($resizedImage !== $workingImage) {
                imagedestroy($resizedImage);
            }
            if ($croppedImage instanceof \GdImage) {
                imagedestroy($croppedImage);
            }
            imagedestroy($originalImage);
            throw new RuntimeException("failed to prepare rembg input for slot {$slot}");
        }

        if ($resizedImage !== $workingImage) {
            imagedestroy($resizedImage);
        }
        if ($croppedImage instanceof \GdImage) {
            imagedestroy($croppedImage);
        }
        imagedestroy($originalImage);

        return [
            'path' => $tempPath,
            'selectionContext' => $selectionContext,
        ];
    }

    private function downloadSourceImage(ScanImage $image, string $workdir): string
    {
        $scanId = (string) $image->scan_id;
        $slot = (int) $image->slot;
        $storedPath = (string) ($image->path_original ?: ScanObjectKeys::imageForSlot($scanId, $slot));
        $extension = pathinfo($storedPath, PATHINFO_EXTENSION) ?: 'jpg';
        $localPath = "{$workdir}/images/{$slot}.{$extension}";

        return $this->objectStorage->downloadStoredPathTo($storedPath, $localPath);
    }

    private function resolveCropWindow(mixed $selection, int $width, int $height, string $mode): ?array
    {
        if (! is_array($selection) || ! isset($selection['bbox']) || ! is_array($selection['bbox'])) {
            return null;
        }

        $bbox = $selection['bbox'];
        $method = strtolower((string) ($selection['method'] ?? 'box'));
        $seed = $this->resolveNormalizedSelectionPoint($selection, $bbox);
        $cropScale = $method === 'tap' ? 0.82 : 1.0;
        $padding = $mode === 'preview' ? 0.04 : 0.02;
        $cropWidthNorm = $this->clamp(((float) ($bbox['width'] ?? 1)) * $cropScale, 0.08, 1);
        $cropHeightNorm = $this->clamp(((float) ($bbox['height'] ?? 1)) * $cropScale, 0.08, 1);

        $left = $this->clamp($seed['x'] - ($cropWidthNorm / 2) - ($cropWidthNorm * $padding), 0, 1);
        $top = $this->clamp($seed['y'] - ($cropHeightNorm / 2) - ($cropHeightNorm * $padding), 0, 1);
        $right = $this->clamp($seed['x'] + ($cropWidthNorm / 2) + ($cropWidthNorm * $padding), 0, 1);
        $bottom = $this->clamp($seed['y'] + ($cropHeightNorm / 2) + ($cropHeightNorm * $padding), 0, 1);

        return [
            'x' => max(0, (int) floor($left * $width)),
            'y' => max(0, (int) floor($top * $height)),
            'width' => max(1, (int) round(($right - $left) * $width)),
            'height' => max(1, (int) round(($bottom - $top) * $height)),
        ];
    }

    private function buildSelectionContext(
        mixed $selection,
        string $mode,
        int $originalWidth,
        int $originalHeight,
        ?array $cropWindow,
        int $preparedWidth,
        int $preparedHeight,
    ): ?array {
        if (! is_array($selection) || ! isset($selection['bbox']) || ! is_array($selection['bbox'])) {
            return null;
        }

        $bbox = $selection['bbox'];
        $seed = $this->resolveNormalizedSelectionPoint($selection, $bbox);
        $cropX = $cropWindow['x'] ?? 0;
        $cropY = $cropWindow['y'] ?? 0;
        $cropWidth = $cropWindow['width'] ?? $originalWidth;
        $cropHeight = $cropWindow['height'] ?? $originalHeight;
        $scaleX = $preparedWidth / max(1, $cropWidth);
        $scaleY = $preparedHeight / max(1, $cropHeight);
        $seedX = (int) round((($seed['x'] * $originalWidth) - $cropX) * $scaleX);
        $seedY = (int) round((($seed['y'] * $originalHeight) - $cropY) * $scaleY);
        $boxWidth = max(12, (int) round(((float) ($bbox['width'] ?? 0.2)) * $originalWidth * $scaleX));
        $boxHeight = max(12, (int) round(((float) ($bbox['height'] ?? 0.2)) * $originalHeight * $scaleY));

        return [
            'mode' => $mode,
            'method' => strtolower((string) ($selection['method'] ?? 'box')),
            'seedX' => $this->clampInt($seedX, 0, max(0, $preparedWidth - 1)),
            'seedY' => $this->clampInt($seedY, 0, max(0, $preparedHeight - 1)),
            'boxWidth' => $boxWidth,
            'boxHeight' => $boxHeight,
            'preparedWidth' => $preparedWidth,
            'preparedHeight' => $preparedHeight,
        ];
    }

    private function refineMaskToSelection(string $outputPath, array $context): void
    {
        if (! function_exists('imagecreatefrompng')) {
            return;
        }

        $image = @imagecreatefrompng($outputPath);
        if (! $image) {
            return;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);
        $seedX = $this->clampInt((int) ($context['seedX'] ?? (int) floor($width / 2)), 0, max(0, $width - 1));
        $seedY = $this->clampInt((int) ($context['seedY'] ?? (int) floor($height / 2)), 0, max(0, $height - 1));
        $alphaThreshold = ($context['method'] ?? 'box') === 'tap' ? 96 : 104;
        $focusRect = $this->resolveFocusRect($context, $width, $height);
        $keep = $this->selectBestMaskComponent($image, $seedX, $seedY, $focusRect, $context, $alphaThreshold);

        if ($keep === null || $keep['count'] === 0) {
            imagedestroy($image);
            return;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = ($y * $width) + $x;
                if ($this->bitsetGet($keep['pixels'], $index)) {
                    continue;
                }

                $rgba = imagecolorat($image, $x, $y);
                imagesetpixel($image, $x, $y, ($rgba & 0x00FFFFFF) | (127 << 24));
            }
        }

        imagepng($image, $outputPath);
        imagedestroy($image);
    }

    private function selectBestMaskComponent(
        \GdImage $image,
        int $seedX,
        int $seedY,
        array $focusRect,
        array $context,
        int $alphaThreshold
    ): ?array {
        $components = $this->collectMaskComponents($image, $focusRect, $alphaThreshold, $seedX, $seedY);

        if ($components === []) {
            return null;
        }

        $best = null;
        $bestScore = null;

        foreach ($components as $component) {
            $score = $this->scoreMaskComponent($component, $context, $focusRect);

            if ($best === null || $bestScore === null || $score > $bestScore) {
                $best = $component;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function resolveFocusRect(array $context, int $width, int $height): array
    {
        $method = $context['method'] ?? 'box';
        $boxWidth = (int) ($context['boxWidth'] ?? max(12, (int) floor($width * 0.2)));
        $boxHeight = (int) ($context['boxHeight'] ?? max(12, (int) floor($height * 0.2)));
        $focusScale = $method === 'tap' ? 0.62 : 0.82;
        $focusWidth = max(16, (int) round($boxWidth * $focusScale));
        $focusHeight = max(16, (int) round($boxHeight * $focusScale));
        $seedX = (int) ($context['seedX'] ?? (int) floor($width / 2));
        $seedY = (int) ($context['seedY'] ?? (int) floor($height / 2));

        return [
            'left' => $this->clampInt((int) round($seedX - ($focusWidth / 2)), 0, max(0, $width - 1)),
            'top' => $this->clampInt((int) round($seedY - ($focusHeight / 2)), 0, max(0, $height - 1)),
            'right' => $this->clampInt((int) round($seedX + ($focusWidth / 2)), 0, max(0, $width - 1)),
            'bottom' => $this->clampInt((int) round($seedY + ($focusHeight / 2)), 0, max(0, $height - 1)),
        ];
    }

    private function findSeedPixel(
        \GdImage $image,
        int $seedX,
        int $seedY,
        array $focusRect,
        int $alphaThreshold
    ): ?array {
        if ($this->isSolidMaskPixel($image, $seedX, $seedY, $focusRect, $alphaThreshold)) {
            return ['x' => $seedX, 'y' => $seedY];
        }

        $maxRadius = max(12, (int) floor(max(
            $focusRect['right'] - $focusRect['left'],
            $focusRect['bottom'] - $focusRect['top'],
        ) / 2));

        for ($radius = 1; $radius <= $maxRadius; $radius++) {
            for ($y = max($focusRect['top'], $seedY - $radius); $y <= min($focusRect['bottom'], $seedY + $radius); $y++) {
                for ($x = max($focusRect['left'], $seedX - $radius); $x <= min($focusRect['right'], $seedX + $radius); $x++) {
                    if ($this->isSolidMaskPixel($image, $x, $y, $focusRect, $alphaThreshold)) {
                        return ['x' => $x, 'y' => $y];
                    }
                }
            }
        }

        return null;
    }

    private function collectMaskComponents(
        \GdImage $image,
        array $focusRect,
        int $alphaThreshold,
        int $seedX,
        int $seedY
    ): array {
        $width = imagesx($image);
        $height = imagesy($image);
        $visited = str_repeat("\0", $width * $height);
        $components = [];

        for ($y = $focusRect['top']; $y <= $focusRect['bottom']; $y++) {
            for ($x = $focusRect['left']; $x <= $focusRect['right']; $x++) {
                $index = ($y * $width) + $x;

                if ($this->bitsetGet($visited, $index) || ! $this->isSolidMaskPixel($image, $x, $y, $focusRect, $alphaThreshold)) {
                    continue;
                }

                $component = $this->floodFromSeed($image, $x, $y, $focusRect, $alphaThreshold, $visited);
                if ($component['count'] === 0) {
                    continue;
                }

                $component['distanceToSeed'] = hypot(
                    max(0, max($component['bounds']['left'] - $seedX, $seedX - $component['bounds']['right'])),
                    max(0, max($component['bounds']['top'] - $seedY, $seedY - $component['bounds']['bottom']))
                );
                $component['containsSeed'] =
                    $seedX >= $component['bounds']['left'] &&
                    $seedX <= $component['bounds']['right'] &&
                    $seedY >= $component['bounds']['top'] &&
                    $seedY <= $component['bounds']['bottom'] &&
                    $this->bitsetGet($component['pixels'], ($seedY * $width) + $seedX);
                $components[] = $component;
            }
        }

        return $components;
    }

    private function scoreMaskComponent(array $component, array $context, array $focusRect): float
    {
        $selectionArea = max(1, ((int) ($context['boxWidth'] ?? 24)) * ((int) ($context['boxHeight'] ?? 24)));
        $componentWidth = max(1, $component['bounds']['right'] - $component['bounds']['left'] + 1);
        $componentHeight = max(1, $component['bounds']['bottom'] - $component['bounds']['top'] + 1);
        $componentArea = max(1, $componentWidth * $componentHeight);
        $focusWidth = max(1, $focusRect['right'] - $focusRect['left'] + 1);
        $focusHeight = max(1, $focusRect['bottom'] - $focusRect['top'] + 1);
        $focusArea = max(1, $focusWidth * $focusHeight);
        $touches = $component['touches'];
        $touchCount = (int) $touches['left'] + (int) $touches['right'] + (int) $touches['top'] + (int) $touches['bottom'];
        $seedReward = $component['containsSeed'] ? 4.5 : 0.0;
        $distancePenalty = min(3.5, ((float) $component['distanceToSeed']) / max(1.0, hypot($focusWidth, $focusHeight) * 0.35));
        $fillRatio = $component['count'] / $componentArea;
        $selectionRatioPenalty = abs(log(max(0.15, min(8.0, $componentArea / $selectionArea))));
        $focusRatioPenalty = max(0.0, ($componentArea / $focusArea) - 0.45) * 3.2;
        $borderPenalty = ($touches['bottom'] ? 1.8 : 0.0) + max(0, $touchCount - 1) * 1.2;
        $thinPenalty = $fillRatio < 0.2 ? (0.2 - $fillRatio) * 4.0 : 0.0;

        return $seedReward - $distancePenalty - $selectionRatioPenalty - $focusRatioPenalty - $borderPenalty - $thinPenalty;
    }

    private function floodFromSeed(
        \GdImage $image,
        int $seedX,
        int $seedY,
        array $focusRect,
        int $alphaThreshold,
        ?string &$visited = null
    ): array {
        $width = imagesx($image);
        $height = imagesy($image);
        $localVisited = $visited ?? str_repeat("\0", $width * $height);
        $keep = str_repeat("\0", $width * $height);
        $queue = new SplQueue();
        $queue->enqueue([$seedX, $seedY]);
        $count = 0;
        $bounds = [
            'left' => $seedX,
            'top' => $seedY,
            'right' => $seedX,
            'bottom' => $seedY,
        ];
        $touches = [
            'left' => false,
            'top' => false,
            'right' => false,
            'bottom' => false,
        ];

        while (! $queue->isEmpty()) {
            [$x, $y] = $queue->dequeue();
            $index = ($y * $width) + $x;

            if ($this->bitsetGet($localVisited, $index)) {
                continue;
            }

            $this->bitsetSet($localVisited, $index);

            if (! $this->isSolidMaskPixel($image, $x, $y, $focusRect, $alphaThreshold)) {
                continue;
            }

            $this->bitsetSet($keep, $index);
            $count += 1;
            $bounds['left'] = min($bounds['left'], $x);
            $bounds['top'] = min($bounds['top'], $y);
            $bounds['right'] = max($bounds['right'], $x);
            $bounds['bottom'] = max($bounds['bottom'], $y);
            $touches['left'] = $touches['left'] || $x <= $focusRect['left'];
            $touches['top'] = $touches['top'] || $y <= $focusRect['top'];
            $touches['right'] = $touches['right'] || $x >= $focusRect['right'];
            $touches['bottom'] = $touches['bottom'] || $y >= $focusRect['bottom'];

            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    if ($dx === 0 && $dy === 0) {
                        continue;
                    }

                    $nextX = $x + $dx;
                    $nextY = $y + $dy;

                    if ($nextX < 0 || $nextY < 0 || $nextX >= $width || $nextY >= $height) {
                        continue;
                    }

                    if ($nextX < $focusRect['left'] || $nextX > $focusRect['right'] || $nextY < $focusRect['top'] || $nextY > $focusRect['bottom']) {
                        continue;
                    }

                    $queue->enqueue([$nextX, $nextY]);
                }
            }
        }

        if ($visited !== null) {
            $visited = $localVisited;
        }

        return [
            'pixels' => $keep,
            'count' => $count,
            'bounds' => $bounds,
            'touches' => $touches,
        ];
    }

    private function resolveNormalizedSelectionPoint(array $selection, array $bbox): array
    {
        if (isset($selection['point']) && is_array($selection['point'])) {
            return [
                'x' => $this->clamp((float) ($selection['point']['x'] ?? 0.5), 0, 1),
                'y' => $this->clamp((float) ($selection['point']['y'] ?? 0.5), 0, 1),
            ];
        }

        return [
            'x' => $this->clamp((float) ($bbox['x'] ?? 0) + (((float) ($bbox['width'] ?? 0.2)) / 2), 0, 1),
            'y' => $this->clamp((float) ($bbox['y'] ?? 0) + (((float) ($bbox['height'] ?? 0.2)) / 2), 0, 1),
        ];
    }

    private function isSolidMaskPixel(
        \GdImage $image,
        int $x,
        int $y,
        array $focusRect,
        int $alphaThreshold
    ): bool {
        if ($x < $focusRect['left'] || $x > $focusRect['right'] || $y < $focusRect['top'] || $y > $focusRect['bottom']) {
            return false;
        }

        $rgba = imagecolorat($image, $x, $y);
        $alpha = ($rgba >> 24) & 0x7F;

        return $alpha <= $alphaThreshold;
    }

    private function bitsetGet(string $bitset, int $index): bool
    {
        return isset($bitset[$index]) && $bitset[$index] === "\1";
    }

    private function bitsetSet(string &$bitset, int $index): void
    {
        $bitset[$index] = "\1";
    }

    private function resizeIfNeeded(\GdImage $image, int $maxDimension): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $largest = max($width, $height);

        if ($largest <= $maxDimension) {
            return $image;
        }

        $scale = $maxDimension / $largest;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $resized;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return min($max, max($min, $value));
    }

    private function formatFailureMessage(int $slot, string $stderr): string
    {
        $tail = trim($stderr);
        $tail = preg_replace('/\s+/', ' ', $tail) ?? $tail;

        if (strlen($tail) > 220) {
            $tail = substr($tail, -220);
        }

        if ($tail === '') {
            $tail = 'unknown error';
        }

        return "rembg failed for slot {$slot}: {$tail}";
    }
}
