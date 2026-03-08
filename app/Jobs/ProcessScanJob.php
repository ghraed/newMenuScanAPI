<?php

namespace App\Jobs;

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

        if (! $job || ! $job->scan) {
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
                    /** @var ScanImage $image */
                    $localOriginalPath = $this->downloadOriginalImage($image, $imageFolder, $objectStorage);
                    $fallbackPreviewSourcePath ??= $localOriginalPath;

                    $rgba = $maskService->generateRgba($image, [
                        'workdir' => $workdir,
                        'input_path' => $localOriginalPath,
                        'output_path' => "{$processedFolder}/{$image->slot}.png",
                    ]);

                    $previewSourcePath ??= $rgba['local_path'];

                    $image->update([
                        'path_rgba' => $rgba['key'],
                    ]);

                    $processed = $index + 1;
                    $progress = 0.100 + (($processed / $totalImages) * 0.400);

                    $job->update([
                        'progress' => round($progress, 3),
                        'message' => "Preprocessing images ({$processed}/{$totalImages})",
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
            $meshroomObjPath = $this->runMeshroom($workdir, $imageFolder, $processedFolder);
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

            $this->runBlenderObjToGlb($meshroomObjPath, $absoluteGlbPath);

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

                $this->runUsdzFromGlb($absoluteGlbPath, $absoluteUsdzPath);
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

            $job->update([
                'status' => 'ready',
                'progress' => 1.000,
                'message' => "meshroom_obj={$meshroomObjPath}",
            ]);

            $job->scan()->update([
                'status' => 'ready',
            ]);
        } catch (Throwable $e) {
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

    private function runMeshroom(string $workdir, string $imageFolder, string $processedFolder): string
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
            $process->run();
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

    private function runBlenderObjToGlb(string $inputObjPath, string $outputGlbPath): void
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
        ]);
        $process->setTimeout(null);

        try {
            $process->run();
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
            throw new RuntimeException('blender failed: GLB output missing');
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

    private function runUsdzFromGlb(string $inputGlbPath, string $outputUsdzPath): void
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
            $process->run();
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
}
