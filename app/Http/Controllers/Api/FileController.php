<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadScanFileRequest;
use App\Models\Job;
use App\Models\ScanImage;
use App\Services\ObjectStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        private readonly ObjectStorageService $objectStorage,
    ) {
    }

    public function show(
        DownloadScanFileRequest $request,
        string $scanId,
        string $type
    ): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $job = Job::query()
            ->with('jobOutput')
            ->where('scan_id', $scanId)
            ->latest()
            ->get()
            ->first(function (Job $job) use ($type) {
                return $job->jobOutput?->pathForType($type) !== null;
            });

        if (! $job || ! $job->jobOutput) {
            throw new HttpResponseException(response()->json([
                'message' => 'File not found',
            ], 404));
        }

        return $this->responseForStoredPath($job->jobOutput->pathForType($type));
    }

    public function rgba(string $scanId, int $slot): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $image = ScanImage::query()
            ->where('scan_id', $scanId)
            ->where('slot', $slot)
            ->first();

        $storedPath = $image?->path_rgba ?: $image?->path_mask;

        if (! $image || ! $storedPath) {
            throw new HttpResponseException(response()->json([
                'message' => 'RGBA image not found',
            ], 404));
        }

        return $this->responseForStoredPath(
            $storedPath,
            ['Content-Type' => 'image/png']
        );
    }

    private function responseForStoredPath(
        ?string $path,
        array $headers = []
    ): StreamedResponse|BinaryFileResponse|RedirectResponse {
        if (! $path) {
            throw new HttpResponseException(response()->json([
                'message' => 'File not found',
            ], 404));
        }

        if ($signedUrl = $this->objectStorage->temporaryUrlIfAvailable($path)) {
            return redirect()->away($signedUrl);
        }

        if (str_starts_with($path, '/')) {
            if (! is_file($path)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'File not found',
                ], 404));
            }

            return response()->file($path, $headers);
        }

        if (! Storage::disk('local')->exists($path)) {
            throw new HttpResponseException(response()->json([
                'message' => 'File not found',
            ], 404));
        }

        return Storage::disk('local')->response($path, null, $headers);
    }
}
