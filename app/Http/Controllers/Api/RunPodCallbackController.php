<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobOutput;
use App\Services\ScanAssetSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RunPodCallbackController extends Controller
{
    public function __construct(
        private readonly ScanAssetSyncService $assetSync,
    ) {
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'localJobId' => ['required', 'uuid'],
            'jobId' => ['required', 'string'],
            'scanId' => ['required', 'uuid'],
            'status' => ['required', 'in:ready,error,canceled'],
            'message' => ['nullable', 'string'],
            'outputs' => ['nullable', 'array'],
            'outputs.objPath' => ['nullable', 'string'],
            'outputs.glbPath' => ['nullable', 'string'],
            'outputs.usdzPath' => ['nullable', 'string'],
            'outputs.previewPath' => ['nullable', 'string'],
        ]);

        $job = Job::query()->with('scan')->findOrFail($validated['localJobId']);

        if (! $job->scan || (string) $job->scan_id !== $validated['scanId']) {
            abort(404);
        }

        if ($job->status === Job::STATUS_CANCELED) {
            return response()->json([
                'ok' => true,
                'ignored' => 'Job already canceled.',
            ]);
        }

        $meta = $job->meta ?? [];
        $meta['runpod'] = array_merge($meta['runpod'] ?? [], [
            'job_id' => $validated['jobId'],
            'completed_at' => now()->toIso8601String(),
            'status' => $validated['status'],
        ]);

        if ($validated['status'] === Job::STATUS_READY) {
            $glbPath = Arr::get($validated, 'outputs.glbPath');

            if (! is_string($glbPath) || $glbPath === '') {
                return response()->json([
                    'message' => 'glbPath is required for ready callbacks.',
                ], 422);
            }

            $usdzPath = Arr::get($validated, 'outputs.usdzPath');
            $previewPath = Arr::get($validated, 'outputs.previewPath');
            $objPath = Arr::get($validated, 'outputs.objPath');

            JobOutput::query()->updateOrCreate(
                ['job_id' => $job->id],
                [
                    'glb_path' => $glbPath,
                    'usdz_path' => is_string($usdzPath) && $usdzPath !== '' ? $usdzPath : null,
                    'preview_path' => is_string($previewPath) && $previewPath !== '' ? $previewPath : null,
                    'obj_path' => is_string($objPath) && $objPath !== '' ? $objPath : null,
                ]
            );

            $this->assetSync->syncDishAssets(
                dishId: (int) ($job->scan->dish_id ?? 0),
                scanId: (string) $job->scan_id,
                glbPath: $glbPath,
                usdzPath: is_string($usdzPath) && $usdzPath !== '' ? $usdzPath : null,
                previewPath: is_string($previewPath) && $previewPath !== '' ? $previewPath : null,
            );

            $job->update([
                'status' => Job::STATUS_READY,
                'progress' => 1,
                'message' => $validated['message'] ?? '3D model ready.',
                'meta' => $meta,
            ]);

            $job->scan()->update([
                'status' => 'ready',
            ]);
        } elseif ($validated['status'] === Job::STATUS_CANCELED) {
            $job->update([
                'status' => Job::STATUS_CANCELED,
                'message' => $validated['message'] ?? 'Canceled by remote worker.',
                'meta' => $meta,
            ]);

            $job->scan()->update([
                'status' => 'uploaded',
            ]);
        } else {
            $job->update([
                'status' => Job::STATUS_ERROR,
                'message' => $validated['message'] ?? 'Remote worker failed.',
                'meta' => $meta,
            ]);

            $job->scan()->update([
                'status' => 'error',
            ]);
        }

        return response()->json([
            'ok' => true,
        ]);
    }
}
