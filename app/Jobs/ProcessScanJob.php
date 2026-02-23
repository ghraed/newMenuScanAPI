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
                'progress' => 0.900,
                'message' => 'Generating placeholder outputs',
            ]);

            usleep(300000);

            $outputsDir = "scans/{$job->scan_id}/outputs";
            $glbPath = "{$outputsDir}/model.glb";
            $usdzPath = "{$outputsDir}/model.usdz";

            Storage::disk('local')->makeDirectory($outputsDir);
            Storage::disk('local')->put($glbPath, '');
            Storage::disk('local')->put($usdzPath, '');

            JobOutput::query()->updateOrCreate(
                ['job_id' => $job->id],
                [
                    'glb_path' => $glbPath,
                    'usdz_path' => $usdzPath,
                ]
            );

            $job->update([
                'status' => 'ready',
                'progress' => 1.000,
                'message' => null,
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
}
