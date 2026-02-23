<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadScanFileRequest;
use App\Models\Job;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function show(DownloadScanFileRequest $request, string $scanId, string $type): StreamedResponse|BinaryFileResponse
    {
        $job = Job::query()
            ->with('jobOutput')
            ->where('scan_id', $scanId)
            ->latest()
            ->get()
            ->first(function (Job $job) use ($type) {
                if (! $job->jobOutput) {
                    return false;
                }

                return $type === 'glb'
                    ? $job->jobOutput->glb_path !== null
                    : $job->jobOutput->usdz_path !== null;
            });

        if (! $job || ! $job->jobOutput) {
            throw new HttpResponseException(response()->json([
                'message' => 'File not found',
            ], 404));
        }

        $path = $type === 'glb' ? $job->jobOutput->glb_path : $job->jobOutput->usdz_path;

        if (! $path) {
            throw new HttpResponseException(response()->json([
                'message' => 'File not found',
            ], 404));
        }

        if (str_starts_with($path, '/')) {
            if (! is_file($path)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'File not found',
                ], 404));
            }

            return response()->file($path);
        }

        if (! Storage::disk('local')->exists($path)) {
            throw new HttpResponseException(response()->json([
                'message' => 'File not found',
            ], 404));
        }

        return Storage::disk('local')->response($path);
    }
}
