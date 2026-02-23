<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\JobOutput;
use App\Models\ScanImage;
use App\Services\MaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $jobId)
    {
    }

    public function handle(MaskService $maskService): void
    {
        $job = Job::query()->with(['scan', 'scan.scanImages'])->find($this->jobId);

        if (! $job || ! $job->scan) {
            return;
        }

        try {
            $job->update([
                'status' => 'processing',
                'progress' => 0.100,
                'message' => 'Preprocessing images',
            ]);

            usleep(300000);

            $images = $job->scan->scanImages->sortBy('slot')->values();
            $totalImages = $images->count();

            if ($totalImages > 0) {
                foreach ($images as $index => $image) {
                    /** @var ScanImage $image */
                    $rgbaPath = $maskService->generateRgba($job->scan_id, (int) $image->slot);

                    $image->update([
                        'path_rgba' => $rgbaPath,
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

            usleep(300000);

            $job->update([
                'progress' => 0.650,
                'message' => 'Running Meshroom photogrammetry',
            ]);

            $meshroomObjPath = $this->runMeshroom($job);

            $job->update([
                'progress' => 0.850,
                'message' => "meshroom_obj={$meshroomObjPath}",
            ]);

            usleep(300000);

            $job->update([
                'progress' => 0.900,
                'message' => 'Converting OBJ to GLB',
            ]);

            $outputsDir = "scans/{$job->scan_id}/outputs";
            $glbPath = "{$outputsDir}/model.glb";
            Storage::disk('local')->makeDirectory($outputsDir);

            $this->runBlenderObjToGlb($meshroomObjPath, storage_path("app/{$glbPath}"));

            $job->update([
                'progress' => 0.970,
                'message' => "meshroom_obj={$meshroomObjPath}",
            ]);

            JobOutput::query()->updateOrCreate(
                ['job_id' => $job->id],
                [
                    'glb_path' => $glbPath,
                    'usdz_path' => null,
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
        }
    }

    private function runMeshroom(Job $job): string
    {
        $inputFolder = $this->resolveMeshroomInputFolder($job->scan_id);
        $pipelineWorkdir = rtrim((string) env('PIPELINE_WORKDIR', 'storage/app/pipeline'), '/');
        $workdir = str_starts_with($pipelineWorkdir, '/')
            ? "{$pipelineWorkdir}/{$job->id}"
            : storage_path("app/pipeline/{$job->id}");
        $outputFolder = "{$workdir}/meshroom-output";

        if (! is_dir($workdir) && ! mkdir($workdir, 0775, true) && ! is_dir($workdir)) {
            throw new RuntimeException('meshroom failed: could not create pipeline workdir');
        }

        if (! is_dir($outputFolder) && ! mkdir($outputFolder, 0775, true) && ! is_dir($outputFolder)) {
            throw new RuntimeException('meshroom failed: could not create output dir');
        }

        $meshroomBin = (string) env('MESHROOM_BIN', 'meshroom_batch');
        $process = new Process([$meshroomBin, '--input', $inputFolder, '--output', $outputFolder], $workdir);
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

    private function resolveMeshroomInputFolder(string $scanId): string
    {
        $rgbaFolder = storage_path("app/scans/{$scanId}/rgba");
        $imageFolder = storage_path("app/scans/{$scanId}/images");

        $rgbaFiles = glob($rgbaFolder.'/*.png') ?: [];
        if ($rgbaFiles !== []) {
            return $rgbaFolder;
        }

        return $imageFolder;
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
}
