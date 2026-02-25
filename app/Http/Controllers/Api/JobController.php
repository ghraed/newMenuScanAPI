<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowJobRequest;
use App\Models\Job;
use Illuminate\Http\JsonResponse;

class JobController extends Controller
{
    public function show(ShowJobRequest $request, string $jobId): JsonResponse
    {
        $job = Job::query()->with('jobOutput')->findOrFail($jobId);

        $response = [
            'status' => $job->status,
            'progress' => (float) $job->progress,
        ];

        $outputs = [];

        if ($job->message !== null) {
            $response['message'] = $job->message;
        }

        if ($job->jobOutput?->glb_path) {
            $outputs['glbUrl'] = route('api.files.show', ['scanId' => $job->scan_id, 'type' => 'glb']);
        }

        if ($job->jobOutput?->usdz_path) {
            $outputs['usdzUrl'] = route('api.files.show', ['scanId' => $job->scan_id, 'type' => 'usdz']);
        }

        if ($outputs !== []) {
            $response['outputs'] = $outputs;
        }

        return response()->json($response);
    }
}
