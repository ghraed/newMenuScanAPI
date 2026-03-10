<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowJobRequest;
use App\Models\Job;
use App\Models\JobOutput;
use App\Services\ObjectStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class JobController extends Controller
{
    public function __construct(
        private readonly ObjectStorageService $objectStorage,
    ) {
    }

    public function show(ShowJobRequest $request, string $jobId): JsonResponse
    {
        $job = Job::query()->with(['jobOutput', 'scan.scanImages'])->findOrFail($jobId);

        $response = [
            'status' => $job->status,
            'progress' => (float) $job->progress,
        ];

        if ($job->message !== null) {
            $response['message'] = $job->message;
        }

        if (($job->type ?? 'model') === 'background') {
            $availableSlots = $job->scan?->scanImages
                ?->filter(fn ($image) => $image->path_rgba !== null || $image->path_mask !== null)
                ->pluck('slot')
                ->sort()
                ->values()
                ->all() ?? [];

            $response['availableSlots'] = $availableSlots;
            $response['previewAvailable'] = $availableSlots !== [] && $job->status !== 'ready';

            return response()->json($response);
        }

        $outputs = $this->buildOutputUrls($job->jobOutput, $job->scan_id);

        if ($outputs !== []) {
            $response['outputs'] = $outputs;
        }

        return response()->json($response);
    }

    public function cancel(string $jobId): JsonResponse
    {
        $job = Job::query()->with('scan')->findOrFail($jobId);

        if (in_array($job->status, [Job::STATUS_READY, Job::STATUS_ERROR, Job::STATUS_CANCELED], true)) {
            return response()->json([
                'jobId' => $job->id,
                'status' => $job->status,
                'progress' => (float) $job->progress,
                'message' => $job->message,
            ]);
        }

        DB::transaction(function () use ($job): void {
            $job->update([
                'status' => Job::STATUS_CANCELED,
                'message' => 'Canceled by user.',
            ]);

            if ($job->scan) {
                $nextScanStatus = ($job->type ?? 'model') === 'background'
                    ? 'uploaded'
                    : 'uploaded';

                $job->scan->update([
                    'status' => $nextScanStatus,
                ]);
            }
        });

        return response()->json([
            'jobId' => $job->id,
            'status' => Job::STATUS_CANCELED,
            'progress' => (float) $job->progress,
            'message' => 'Canceled by user.',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildOutputUrls(?JobOutput $jobOutput, string $scanId): array
    {
        if (! $jobOutput) {
            return [];
        }

        $outputs = [];

        foreach ($jobOutput->availablePaths() as $type => $path) {
            $outputs["{$type}Url"] = route('api.files.show', ['scanId' => $scanId, 'type' => $type]);

            if ($signedUrl = $this->objectStorage->temporaryUrlIfAvailable($path)) {
                $outputs["{$type}SignedUrl"] = $signedUrl;
            }
        }

        return $outputs;
    }
}
