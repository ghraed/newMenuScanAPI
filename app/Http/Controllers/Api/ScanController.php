<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowScanRequest;
use App\Http\Requests\StoreScanImageRequest;
use App\Http\Requests\StoreScanRequest;
use App\Http\Requests\SubmitScanRequest;
use App\Jobs\ProcessScanJob;
use App\Models\Job;
use App\Models\Scan;
use App\Models\ScanImage;
use App\Services\MaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanController extends Controller
{
    public function store(StoreScanRequest $request): JsonResponse
    {
        $scan = Scan::query()->create([
            'device_id' => $request->input('deviceId'),
            'target_type' => $request->input('targetType'),
            'scale_meters' => $request->input('scaleMeters'),
            'slots_total' => $request->input('slotsTotal', 24),
        ]);

        return response()->json([
            'scanId' => $scan->id,
        ], 201);
    }

    public function storeImage(StoreScanImageRequest $request, string $scanId): JsonResponse
    {
        $scan = Scan::query()->findOrFail($scanId);

        $slot = (int) $request->integer('slot');
        $path = $request->file('image')->storeAs(
            "scans/{$scan->id}/images",
            "{$slot}.jpg",
            'local'
        );

        ScanImage::query()->updateOrCreate(
            [
                'scan_id' => $scan->id,
                'slot' => $slot,
            ],
            [
                'heading' => $request->input('heading'),
                'path_original' => $path,
            ]
        );

        if ($scan->status === 'draft') {
            $scan->update(['status' => 'uploaded']);
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    public function preprocess(SubmitScanRequest $request, string $scanId, MaskService $maskService): JsonResponse
    {
        $scan = Scan::query()->with('scanImages')->findOrFail($scanId);
        $images = $scan->scanImages->sortBy('slot')->values();

        if ($images->isEmpty()) {
            return response()->json([
                'message' => 'No uploaded images to preprocess.',
            ], 422);
        }

        $processed = 0;

        try {
            foreach ($images as $image) {
                /** @var ScanImage $image */
                $rgbaPath = $maskService->generateRgba($scan->id, (int) $image->slot);

                $image->update([
                    'path_rgba' => $rgbaPath,
                ]);

                $processed += 1;
            }

            if ($scan->status === 'draft') {
                $scan->update(['status' => 'uploaded']);
            }

            return response()->json([
                'ok' => true,
                'processed' => $processed,
                'total' => $images->count(),
            ]);
        } catch (\Throwable $error) {
            Log::error('Scan background preprocessing failed', [
                'scan_id' => $scanId,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to preprocess scan images.',
            ], 500);
        }
    }

    public function submit(SubmitScanRequest $request, string $scanId): JsonResponse
    {
        $job = DB::transaction(function () use ($scanId) {
            $scan = Scan::query()->lockForUpdate()->findOrFail($scanId);

            $job = Job::query()->create([
                'scan_id' => $scan->id,
                'status' => 'queued',
                'progress' => 0,
            ]);

            $scan->update([
                'status' => 'processing',
            ]);

            return $job;
        });

        ProcessScanJob::dispatch($job->id);

        return response()->json([
            'jobId' => $job->id,
            'status' => $job->status,
        ]);
    }

    public function show(ShowScanRequest $request, string $scanId): JsonResponse
    {
        $scan = Scan::query()
            ->withCount('scanImages')
            ->with(['jobs' => function ($query) {
                $query->latest();
            }, 'jobs.jobOutput'])
            ->findOrFail($scanId);

        $latestOutputJob = $scan->jobs->first(function (Job $job) {
            return $job->jobOutput
                && ($job->jobOutput->glb_path !== null || $job->jobOutput->usdz_path !== null);
        });

        $response = [
            'scanId' => $scan->id,
            'deviceId' => $scan->device_id,
            'targetType' => $scan->target_type,
            'scaleMeters' => (float) $scan->scale_meters,
            'slotsTotal' => $scan->slots_total,
            'status' => $scan->status,
            'imagesCount' => $scan->scan_images_count,
        ];

        $outputs = [];

        if ($latestOutputJob?->jobOutput?->glb_path) {
            $outputs['glbUrl'] = route('api.files.show', ['scanId' => $scan->id, 'type' => 'glb']);
        }

        if ($latestOutputJob?->jobOutput?->usdz_path) {
            $outputs['usdzUrl'] = route('api.files.show', ['scanId' => $scan->id, 'type' => 'usdz']);
        }

        if ($outputs !== []) {
            $response['outputs'] = $outputs;
        }

        return response()->json($response);
    }
}
