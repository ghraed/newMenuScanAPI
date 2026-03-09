<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowJobRequest;
use App\Models\Job;
use App\Models\JobOutput;
use App\Services\ObjectStorageService;
use Illuminate\Http\JsonResponse;

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
