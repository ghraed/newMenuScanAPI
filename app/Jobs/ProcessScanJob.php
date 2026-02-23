<?php

namespace App\Jobs;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        if ($job->status === 'queued') {
            $job->update([
                'status' => 'processing',
                'progress' => 0.050,
                'message' => 'Processing started',
            ]);
        }
    }
}
