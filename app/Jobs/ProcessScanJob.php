<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\JobOutput;
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

    public function handle(): void
    {
        $job = Job::query()->find($this->jobId);

        if (! $job) {
            return;
        }

        try {
            $job->update([
                'status' => 'processing',
                'progress' => 0.100,
                'message' => 'Preparing scan processing',
            ]);

            usleep(300000);

            $job->update([
                'progress' => 0.500,
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
                'message' => $e->getMessage(),
            ]);

            $job->scan()->update([
                'status' => 'error',
            ]);
        }
    }
}
