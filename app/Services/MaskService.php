<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class MaskService
{
    public function generateRgba(string $scanId, int $slot): string
    {
        $inputRelative = "scans/{$scanId}/images/{$slot}.jpg";
        $outputRelative = "scans/{$scanId}/rgba/{$slot}.png";

        $inputPath = Storage::disk('local')->path($inputRelative);
        $outputPath = Storage::disk('local')->path($outputRelative);
        $outputDir = dirname($outputPath);

        if (! is_file($inputPath)) {
            throw new RuntimeException("rembg input missing for slot {$slot}: {$inputPath}");
        }

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException("failed to create rgba directory for slot {$slot}");
        }

        $binary = (string) env('REMBG_BIN', 'rembg');
        $process = new Process([$binary, 'i', $inputPath, $outputPath]);
        $process->setTimeout(600);

        try {
            $process->run();
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

        return $outputRelative;
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
