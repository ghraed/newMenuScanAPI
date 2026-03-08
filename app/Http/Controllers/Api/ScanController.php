<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartBackgroundRemovalRequest;
use App\Http\Requests\ShowScanRequest;
use App\Http\Requests\StoreScanImageRequest;
use App\Http\Requests\StoreScanRequest;
use App\Http\Requests\SubmitScanRequest;
use App\Jobs\ProcessBackgroundRemovalJob;
use App\Jobs\ProcessScanJob;
use App\Models\Job;
use App\Models\JobOutput;
use App\Models\Scan;
use App\Models\ScanImage;
use App\Services\ObjectStorageService;
use App\Support\ScanObjectKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ScanController extends Controller
{
    public function __construct(
        private readonly ObjectStorageService $objectStorage,
    ) {
    }

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
        $path = ScanObjectKeys::imageForSlot($scan->id, $slot);
        $sourcePath = $request->file('image')->getRealPath() ?: $request->file('image')->path();

        $this->objectStorage->uploadFile($path, $sourcePath, [
            'ContentType' => $request->file('image')->getMimeType() ?: 'image/jpeg',
        ]);

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

    public function preprocess(StartBackgroundRemovalRequest $request, string $scanId): JsonResponse
    {
        $scan = Scan::query()->with('scanImages')->findOrFail($scanId);
        $images = $scan->scanImages->sortBy('slot')->values();

        if ($images->isEmpty()) {
            return response()->json([
                'message' => 'No uploaded images to preprocess.',
            ], 422);
        }

        $activeJob = Job::query()
            ->where('scan_id', $scan->id)
            ->where('type', 'background')
            ->whereIn('status', ['queued', 'processing', 'partial'])
            ->latest()
            ->first();

        if ($activeJob) {
            return response()->json([
                'jobId' => $activeJob->id,
                'status' => $activeJob->status,
                'progress' => (float) $activeJob->progress,
                'availableSlots' => $images
                    ->filter(fn (ScanImage $image) => $image->path_rgba !== null)
                    ->pluck('slot')
                    ->values(),
                'previewAvailable' => $images->contains(fn (ScanImage $image) => $image->path_rgba !== null),
                'message' => $activeJob->message,
            ]);
        }

        $staleProcessedPaths = $images
            ->pluck('path_rgba')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($staleProcessedPaths !== []) {
            $this->objectStorage->delete($staleProcessedPaths);
        }

        ScanImage::query()
            ->where('scan_id', $scan->id)
            ->update(['path_rgba' => null]);

        $job = Job::query()->create([
            'scan_id' => $scan->id,
            'type' => 'background',
            'status' => 'queued',
            'progress' => 0,
            'meta' => [
                'objectSelection' => $request->validated('objectSelection'),
            ],
        ]);

        if ($scan->status === 'draft') {
            $scan->update(['status' => 'uploaded']);
        }

        ProcessBackgroundRemovalJob::dispatch($job->id);

        return response()->json([
            'jobId' => $job->id,
            'status' => $job->status,
            'progress' => (float) $job->progress,
            'availableSlots' => [],
            'previewAvailable' => false,
        ]);
    }

    public function submit(SubmitScanRequest $request, string $scanId): JsonResponse
    {
        $job = DB::transaction(function () use ($scanId) {
            $scan = Scan::query()->lockForUpdate()->findOrFail($scanId);

            $job = Job::query()->create([
                'scan_id' => $scan->id,
                'type' => 'model',
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
            return $job->jobOutput && $job->jobOutput->availablePaths() !== [];
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

        $outputs = $this->buildOutputUrls($latestOutputJob?->jobOutput, $scan->id);

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
