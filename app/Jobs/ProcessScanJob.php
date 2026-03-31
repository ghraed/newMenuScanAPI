<?php

namespace App\Jobs;

use App\Models\DishAsset;
use App\Models\Job;
use App\Models\JobOutput;
use App\Models\ScanImage;
use App\Services\MaskService;
use App\Services\ObjectStorageService;
use App\Support\ScanObjectKeys;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $jobId)
    {
    }

    public function handle(MaskService $maskService, ObjectStorageService $objectStorage): void
    {
        $job = Job::query()->with(['scan', 'scan.scanImages'])->find($this->jobId);

        if (! $job || ! $job->scan || $job->isCanceled()) {
            return;
        }

        $workdir = $this->createWorkdir($job->id);

        try {
            $job->update([
                'status' => 'processing',
                'progress' => 0.100,
                'message' => 'Preprocessing images',
            ]);

            usleep(300000);

            $images = $job->scan->scanImages->sortBy('slot')->values();
            $totalImages = $images->count();
            $imageFolder = "{$workdir}/images";
            $processedFolder = "{$workdir}/processed";
            $outputsFolder = "{$workdir}/outputs";
            $previewSourcePath = null;
            $fallbackPreviewSourcePath = null;

            if ($totalImages > 0) {
                foreach ($images as $index => $image) {
                    $this->throwIfCanceled($job);
                    /** @var ScanImage $image */
                    $downloadedOriginalPath = $this->downloadOriginalImage($image, $imageFolder, $objectStorage);
                    $localOriginalPath = $this->prepareMeshroomInputImage(
                        $maskService,
                        $downloadedOriginalPath,
                        $imageFolder,
                        (int) $image->slot
                    );
                    $fallbackPreviewSourcePath ??= $localOriginalPath;

                    $existingProcessedPath = $this->downloadExistingProcessedImage($image, $processedFolder, $objectStorage);

                    if ($existingProcessedPath !== null) {
                        $previewSourcePath ??= $existingProcessedPath;
                    } else {
                        $rgba = $maskService->generateRgba($image, [
                            'workdir' => $workdir,
                            'input_path' => $localOriginalPath,
                            'output_path' => "{$processedFolder}/{$image->slot}.png",
                            'should_cancel' => fn (): bool => $job->isCanceled(),
                        ]);

                        $previewSourcePath ??= $rgba['local_path'];

                        $image->update([
                            'path_rgba' => $rgba['key'],
                        ]);
                    }

                    $processed = $index + 1;
                    $progress = 0.100 + (($processed / $totalImages) * 0.400);

                    $job->update([
                        'progress' => round($progress, 3),
                        'message' => $existingProcessedPath !== null
                            ? "Using existing background removal ({$processed}/{$totalImages})"
                            : "Preprocessing images ({$processed}/{$totalImages})",
                    ]);
                }
            } else {
                $job->update([
                    'progress' => 0.500,
                    'message' => 'No images to preprocess',
                ]);
            }

            $previewPath = null;

            if ($previewSourcePath !== null || $fallbackPreviewSourcePath !== null) {
                $previewLocalPath = "{$outputsFolder}/preview.jpg";
                $this->createPreviewImage($previewSourcePath, $fallbackPreviewSourcePath, $previewLocalPath);
                $previewPath = $objectStorage->uploadFile(
                    ScanObjectKeys::previewImage($job->scan_id),
                    $previewLocalPath,
                    ['ContentType' => 'image/jpeg']
                );
            }

            usleep(300000);

            $job->update([
                'progress' => 0.650,
                'message' => 'Running Meshroom photogrammetry',
            ]);

            // Meshroom still needs a local working folder, so the B2 inputs are hydrated locally first.
            $this->throwIfCanceled($job);
            $meshroomObjPath = $this->runMeshroom($workdir, $imageFolder, $processedFolder, $job);
            $objPath = $objectStorage->uploadFile(
                ScanObjectKeys::modelObj($job->scan_id),
                $meshroomObjPath,
                ['ContentType' => 'text/plain']
            );

            $job->update([
                'progress' => 0.850,
                'message' => "meshroom_obj={$meshroomObjPath}",
            ]);

            usleep(300000);

            $job->update([
                'progress' => 0.900,
                'message' => 'Converting OBJ to GLB',
            ]);

            $absoluteGlbPath = "{$outputsFolder}/model.glb";
            $absoluteUsdzPath = "{$outputsFolder}/model.usdz";

            $this->throwIfCanceled($job);
            $this->runBlenderObjToGlb(
                $meshroomObjPath,
                $absoluteGlbPath,
                $job,
                (float) ($job->scan->scale_meters ?? 0)
            );

            $glbPath = $objectStorage->uploadFile(
                ScanObjectKeys::modelGlb($job->scan_id),
                $absoluteGlbPath,
                ['ContentType' => 'model/gltf-binary']
            );

            $job->update([
                'progress' => 0.970,
                'message' => "meshroom_obj={$meshroomObjPath}",
            ]);

            $storedUsdzPath = null;

            if ($this->shouldGenerateUsdz()) {
                $job->update([
                    'progress' => 0.985,
                    'message' => 'Converting GLB to USDZ',
                ]);

                $this->throwIfCanceled($job);
                $this->runUsdzFromGlb($absoluteGlbPath, $absoluteUsdzPath, $job);
                $storedUsdzPath = $objectStorage->uploadFile(
                    ScanObjectKeys::modelUsdz($job->scan_id),
                    $absoluteUsdzPath,
                    ['ContentType' => 'model/vnd.usdz+zip']
                );

                $job->update([
                    'progress' => 0.995,
                    'message' => "meshroom_obj={$meshroomObjPath}",
                ]);
            }

            JobOutput::query()->updateOrCreate(
                ['job_id' => $job->id],
                [
                    'glb_path' => $glbPath,
                    'usdz_path' => $storedUsdzPath,
                    'preview_path' => $previewPath,
                    'obj_path' => $objPath,
                ]
            );

            $this->syncDishAssets(
                (int) $job->scan->dish_id,
                (string) $job->scan_id,
                $glbPath,
                $storedUsdzPath,
                $previewPath,
                $absoluteGlbPath,
                $storedUsdzPath ? $absoluteUsdzPath : null,
                $previewPath ? $previewLocalPath : null,
                $objectStorage,
            );

            $job->update([
                'status' => 'ready',
                'progress' => 1.000,
                'message' => "meshroom_obj={$meshroomObjPath}",
            ]);

            $job->scan()->update([
                'status' => 'ready',
            ]);
        } catch (Throwable $e) {
            if ($job->isCanceled()) {
                $job->scan()->update([
                    'status' => 'uploaded',
                ]);

                return;
            }

            $job->update([
                'status' => 'error',
                'progress' => $job->progress ?? 0,
                'message' => $e->getMessage(),
            ]);

            $job->scan()->update([
                'status' => 'error',
            ]);
        } finally {
            File::deleteDirectory($workdir);
        }
    }

    private function runMeshroom(string $workdir, string $imageFolder, string $processedFolder, Job $job): string
    {
        $inputFolder = $this->resolveMeshroomInputFolder($imageFolder, $processedFolder);
        $outputFolder = "{$workdir}/meshroom-output";

        if (! is_dir($outputFolder) && ! mkdir($outputFolder, 0775, true) && ! is_dir($outputFolder)) {
            throw new RuntimeException('meshroom failed: could not create output dir');
        }

        $meshroomBin = (string) env('MESHROOM_BIN', 'meshroom_batch');
        $meshroomCommand = [$meshroomBin, '--input', $inputFolder, '--output', $outputFolder];

        $forceCpuExtraction = filter_var(
            (string) env('MESHROOM_FORCE_CPU_EXTRACTION', 'true'),
            FILTER_VALIDATE_BOOL
        );
        if ($forceCpuExtraction) {
            $meshroomCommand[] = '--paramOverrides';
            $meshroomCommand[] = 'FeatureExtraction:forceCpuExtraction=1';
        }

        $process = new Process($meshroomCommand, $workdir);
        $process->setTimeout(null);

        try {
            $this->runManagedProcess($process, $job);
        } catch (Throwable $e) {
            throw new RuntimeException(
                $this->formatProcessTail('meshroom failed', $process->getOutput(), $process->getErrorOutput() ?: $e->getMessage()),
                previous: $e
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                $this->formatProcessTail('meshroom failed', $process->getOutput(), $process->getErrorOutput())
            );
        }

        $bestObj = $this->locateBestObj($outputFolder);

        if (! $bestObj) {
            throw new RuntimeException('meshroom failed: no OBJ found in output');
        }

        return $bestObj;
    }

    private function createWorkdir(string $jobId): string
    {
        $configured = rtrim((string) env('PIPELINE_WORKDIR', storage_path('app/pipeline')), '/');
        $baseDir = str_starts_with($configured, '/')
            ? $configured
            : base_path($configured);
        $workdir = "{$baseDir}/{$jobId}";

        if (! is_dir($workdir) && ! mkdir($workdir, 0775, true) && ! is_dir($workdir)) {
            throw new RuntimeException('meshroom failed: could not create pipeline workdir');
        }

        return $workdir;
    }

    private function syncDishAssets(
        int $dishId,
        string $scanId,
        string $glbPath,
        ?string $usdzPath,
        ?string $previewPath,
        ?string $glbLocalPath,
        ?string $usdzLocalPath,
        ?string $previewLocalPath,
        ObjectStorageService $objectStorage
    ): void {
        if ($dishId <= 0) {
            return;
        }

        $storageDisk = $objectStorage->diskName();

        $this->replaceDishAsset(
            dishId: $dishId,
            assetType: 'glb',
            storageDisk: $storageDisk,
            storedPath: $glbPath,
            mimeType: 'model/gltf-binary',
            localPath: $glbLocalPath,
            scanId: $scanId,
        );

        if ($usdzPath) {
            $this->replaceDishAsset(
                dishId: $dishId,
                assetType: 'usdz',
                storageDisk: $storageDisk,
                storedPath: $usdzPath,
                mimeType: 'model/vnd.usdz+zip',
                localPath: $usdzLocalPath,
                scanId: $scanId,
            );
        }

        if ($previewPath) {
            $this->replaceDishAsset(
                dishId: $dishId,
                assetType: 'preview_image',
                storageDisk: $storageDisk,
                storedPath: $previewPath,
                mimeType: 'image/jpeg',
                localPath: $previewLocalPath,
                scanId: $scanId,
            );
        }
    }

    private function replaceDishAsset(
        int $dishId,
        string $assetType,
        string $storageDisk,
        string $storedPath,
        string $mimeType,
        ?string $localPath,
        string $scanId
    ): void {
        DishAsset::query()
            ->where('dish_id', $dishId)
            ->where('asset_type', $assetType)
            ->get()
            ->each(function (DishAsset $existingAsset): void {
                $this->deleteExistingAssetFile($existingAsset);
                $existingAsset->delete();
            });

        $asset = DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dishId,
            'asset_type' => $assetType,
            'storage_disk' => $storageDisk,
            'file_path' => $storedPath,
            'glb_path' => $assetType === 'glb' ? $storedPath : null,
            'usdz_path' => $assetType === 'usdz' ? $storedPath : null,
            'file_url' => '',
            'file_size' => $this->resolveLocalFileSize($localPath),
            'mime_type' => $mimeType,
            'metadata' => [
                'source' => 'scan_pipeline',
                'scan_id' => $scanId,
                'uploaded_at' => now()->toIso8601String(),
            ],
        ]);

        $asset->update([
            'file_url' => $this->buildMenuAssetUrl((int) $asset->id),
        ]);
    }

    private function deleteExistingAssetFile(DishAsset $asset): void
    {
        if (! $asset->file_path) {
            return;
        }

        $disk = $asset->storage_disk ?: 'public';

        try {
            Storage::disk($disk)->delete($asset->file_path);
        } catch (Throwable) {
            // Keep asset replacement resilient if the old file is already gone.
        }
    }

    private function buildMenuAssetUrl(int $assetId): string
    {
        $baseUrl = rtrim((string) env('MENU_API_URL', ''), '/');
        $path = "/api/assets/{$assetId}/file";

        return $baseUrl === '' ? $path : $baseUrl.$path;
    }

    private function resolveLocalFileSize(?string $localPath): ?int
    {
        if (! $localPath || ! is_file($localPath)) {
            return null;
        }

        $size = @filesize($localPath);

        return is_int($size) ? $size : null;
    }

    private function downloadOriginalImage(
        ScanImage $image,
        string $imageFolder,
        ObjectStorageService $objectStorage
    ): string {
        $storedPath = (string) ($image->path_original ?: ScanObjectKeys::imageForSlot($image->scan_id, (int) $image->slot));
        $extension = pathinfo($storedPath, PATHINFO_EXTENSION) ?: 'jpg';
        $localPath = "{$imageFolder}/{$image->slot}.{$extension}";

        return $objectStorage->downloadStoredPathTo($storedPath, $localPath);
    }

    private function prepareMeshroomInputImage(
        MaskService $maskService,
        string $sourcePath,
        string $imageFolder,
        int $slot
    ): string {
        $optimizedPath = "{$imageFolder}/{$slot}.jpg";

        try {
            $resultPath = $maskService->optimizeJpegForPipeline($sourcePath, $optimizedPath, [
                'max_dimension' => (int) env('MESHROOM_IMAGE_MAX_DIMENSION', env('PIPELINE_IMAGE_MAX_DIMENSION', 2200)),
                'quality' => (int) env('MESHROOM_IMAGE_JPEG_QUALITY', env('PIPELINE_IMAGE_JPEG_QUALITY', 88)),
            ]);

            if ($sourcePath !== $resultPath && is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            return $resultPath;
        } catch (Throwable) {
            return $sourcePath;
        }
    }

    private function downloadExistingProcessedImage(
        ScanImage $image,
        string $processedFolder,
        ObjectStorageService $objectStorage
    ): ?string {
        $storedPath = trim((string) $image->path_rgba);

        if ($storedPath === '') {
            return null;
        }

        $extension = pathinfo($storedPath, PATHINFO_EXTENSION) ?: 'png';
        $localPath = "{$processedFolder}/{$image->slot}.{$extension}";

        try {
            return $objectStorage->downloadStoredPathTo($storedPath, $localPath);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveMeshroomInputFolder(string $imageFolder, string $processedFolder): string
    {
        $processedFiles = glob($processedFolder.'/*.png') ?: [];
        $inputSource = strtolower(trim((string) env('MESHROOM_INPUT_SOURCE', 'original')));

        if ($inputSource === 'rgba') {
            if ($processedFiles !== []) {
                return $processedFolder;
            }
        }

        if ($inputSource === 'auto') {
            if ($processedFiles !== []) {
                return $processedFolder;
            }
        }

        return $imageFolder;
    }

    private function createPreviewImage(?string $preferredSourcePath, ?string $fallbackSourcePath, string $outputPath): void
    {
        $sourceCandidates = array_values(array_filter(
            [$preferredSourcePath, $fallbackSourcePath],
            static fn (?string $path): bool => is_string($path) && $path !== '' && is_file($path)
        ));

        if ($sourceCandidates === []) {
            throw new RuntimeException('preview generation failed: no source image available');
        }

        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException('preview generation failed: could not create output dir');
        }

        if (function_exists('imagecreatefromstring')) {
            foreach ($sourceCandidates as $sourcePath) {
                $contents = @file_get_contents($sourcePath);

                if ($contents === false) {
                    continue;
                }

                $image = @imagecreatefromstring($contents);

                if (! $image) {
                    continue;
                }

                if (! imagejpeg($image, $outputPath, 82)) {
                    imagedestroy($image);
                    throw new RuntimeException('preview generation failed: could not write preview image');
                }

                imagedestroy($image);

                return;
            }
        }

        foreach ($sourceCandidates as $sourcePath) {
            $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));

            if (! in_array($extension, ['jpg', 'jpeg'], true)) {
                continue;
            }

            if (! copy($sourcePath, $outputPath)) {
                throw new RuntimeException('preview generation failed: could not copy preview image');
            }

            return;
        }

        throw new RuntimeException('preview generation failed: unsupported image format');
    }

    private function locateBestObj(string $outputFolder): ?string
    {
        if (! is_dir($outputFolder)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outputFolder, \FilesystemIterator::SKIP_DOTS)
        );

        $bestPath = null;
        $bestSize = -1;
        $bestMTime = -1;

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'obj') {
                continue;
            }

            $size = $file->getSize();
            $mtime = $file->getMTime();

            if ($size > $bestSize || ($size === $bestSize && $mtime > $bestMTime)) {
                $bestSize = $size;
                $bestMTime = $mtime;
                $bestPath = $file->getPathname();
            }
        }

        return $bestPath;
    }

    private function formatProcessTail(string $prefix, string $stdout, string $stderr): string
    {
        $combined = trim($stderr) !== '' ? $stderr : $stdout;
        $combined = preg_replace('/\s+/', ' ', trim($combined)) ?? trim($combined);

        if ($combined === '') {
            return "{$prefix}: unknown error";
        }

        if (strlen($combined) > 240) {
            $combined = substr($combined, -240);
        }

        return "{$prefix}: {$combined}";
    }

    private function runBlenderObjToGlb(
        string $inputObjPath,
        string $outputGlbPath,
        Job $job,
        float $targetWidthMeters = 0.0
    ): void
    {
        $blenderBin = (string) env('BLENDER_BIN', 'blender');
        $scriptPath = base_path('scripts/obj_to_glb.py');

        if (! is_file($scriptPath)) {
            throw new RuntimeException('blender failed: conversion script missing');
        }

        $outputDir = dirname($outputGlbPath);
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException('blender failed: could not create output dir');
        }

        $process = new Process([
            $blenderBin,
            '-b',
            '-P',
            $scriptPath,
            '--',
            $inputObjPath,
            $outputGlbPath,
            number_format(max(0, $targetWidthMeters), 6, '.', ''),
            (string) max(0, (int) env('GLB_TARGET_TRIANGLES', 120000)),
            (string) max(512, (int) env('GLB_MAX_TEXTURE_SIZE', 2048)),
        ]);
        $process->setTimeout(null);

        try {
            $this->runManagedProcess($process, $job);
        } catch (Throwable $e) {
            throw new RuntimeException(
                $this->formatProcessTail('blender failed', $process->getOutput(), $process->getErrorOutput() ?: $e->getMessage()),
                previous: $e
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                $this->formatProcessTail('blender failed', $process->getOutput(), $process->getErrorOutput())
            );
        }

        if (! is_file($outputGlbPath)) {
            throw new RuntimeException(
                $this->formatProcessTail('blender failed: GLB output missing', $process->getOutput(), $process->getErrorOutput())
            );
        }
    }

    private function shouldGenerateUsdz(): bool
    {
        $usdzBin = trim((string) env('USDZ_BIN', ''));

        if ($usdzBin === '') {
            return false;
        }

        return is_executable($usdzBin);
    }

    private function runUsdzFromGlb(string $inputGlbPath, string $outputUsdzPath, Job $job): void
    {
        $usdzBin = trim((string) env('USDZ_BIN', ''));

        if ($usdzBin === '' || ! is_executable($usdzBin)) {
            throw new RuntimeException('usdz failed: USDZ_BIN is not executable');
        }

        $outputDir = dirname($outputUsdzPath);
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException('usdz failed: could not create output dir');
        }

        $process = new Process([
            $usdzBin,
            $inputGlbPath,
            $outputUsdzPath,
        ]);
        $process->setTimeout(null);

        try {
            $this->runManagedProcess($process, $job);
        } catch (Throwable $e) {
            throw new RuntimeException(
                $this->formatProcessTail('usdz failed', $process->getOutput(), $process->getErrorOutput() ?: $e->getMessage()),
                previous: $e
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                $this->formatProcessTail('usdz failed', $process->getOutput(), $process->getErrorOutput())
            );
        }

        if (! is_file($outputUsdzPath)) {
            throw new RuntimeException('usdz failed: USDZ output missing');
        }
    }

    private function throwIfCanceled(Job $job): void
    {
        if ($job->isCanceled()) {
            throw new RuntimeException('Job canceled.');
        }
    }

    private function runManagedProcess(Process $process, Job $job): void
    {
        $process->start();

        while ($process->isRunning()) {
            if ($job->isCanceled()) {
                $process->stop(1, 9);
                throw new RuntimeException('Job canceled.');
            }

            usleep(250000);
        }

        $process->wait();
    }
}
