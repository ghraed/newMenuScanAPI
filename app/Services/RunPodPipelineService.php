<?php

namespace App\Services;

use App\Models\Job;
use App\Models\ScanImage;
use App\Support\ScanObjectKeys;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use RuntimeException;

class RunPodPipelineService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ObjectStorageService $objectStorage,
    ) {
    }

    public function isEnabled(): bool
    {
        return filter_var((string) config('services.runpod.enabled', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{jobId:string,status:string}
     */
    public function submitJob(Job $job): array
    {
        $scan = $job->scan?->loadMissing('scanImages');

        if (! $scan) {
            throw new RuntimeException('RunPod submission failed: scan missing.');
        }

        $images = $scan->scanImages->sortBy('slot')->values();
        $processedUrls = [];
        $hasCompletedBackgroundRemoval = $scan->jobs()
            ->where('type', 'background')
            ->where('status', Job::STATUS_READY)
            ->exists();

        if ($hasCompletedBackgroundRemoval && $images->isNotEmpty()) {
            foreach ($images as $image) {
                $processedPath = trim((string) $image->path_rgba);
                $signedUrl = $processedPath !== ''
                    ? $this->objectStorage->temporaryUrlIfAvailable($processedPath, 120)
                    : null;

                if (! $signedUrl) {
                    $processedUrls = [];
                    break;
                }

                $processedUrls[] = $signedUrl;
            }
        }

        $reuseBackgroundRemoval = count($processedUrls) === $images->count() && $images->isNotEmpty();

        $callbackUrl = rtrim((string) config('app.url'), '/').'/api/internal/runpod/jobs/complete';
        $callbackToken = trim((string) config('services.runpod.callback_token'));

        if ($callbackToken === '') {
            throw new RuntimeException('RunPod submission failed: callback token missing.');
        }

        $b2Bucket = trim((string) env('B2_BUCKET', ''));
        $b2Endpoint = trim((string) env('B2_ENDPOINT', ''));
        $b2Region = trim((string) env('B2_REGION', ''));

        if ($b2Bucket === '' || $b2Endpoint === '' || $b2Region === '') {
            throw new RuntimeException('RunPod submission failed: B2 output configuration is incomplete.');
        }

        $payload = [
            'localJobId' => $job->id,
            'scanId' => $scan->id,
            'images' => $images
                ->map(fn (ScanImage $image, int $index) => [
                    'slot' => (int) $image->slot,
                    'heading' => $image->heading,
                    'url' => $reuseBackgroundRemoval ? $processedUrls[$index] : $this->resolveImageUrl($image),
                ])
                ->all(),
            'scaleMeters' => (float) ($scan->scale_meters ?? 0),
            'outputs' => [
                'uploadMode' => 'b2',
                'b2' => [
                    'bucket' => $b2Bucket,
                    'endpoint' => $b2Endpoint,
                    'region' => $b2Region,
                    'keyPrefix' => rtrim("scans/{$scan->id}/outputs/", '/').'/',
                ],
            ],
            'options' => [
                'removeBackground' => ! $reuseBackgroundRemoval,
                'generatePreview' => true,
                'generateUsdz' => true,
            ],
            'callback' => [
                'url' => $callbackUrl,
                'token' => $callbackToken,
            ],
        ];

        $response = $this->client()->post('/jobs', $payload)->throw()->json();

        $remoteJobId = trim((string) Arr::get($response, 'jobId', ''));
        $status = trim((string) Arr::get($response, 'status', 'queued'));

        if ($remoteJobId === '') {
            throw new RuntimeException('RunPod submission failed: remote job id missing.');
        }

        return [
            'jobId' => $remoteJobId,
            'status' => $status !== '' ? $status : 'queued',
        ];
    }

    public function cancelJob(string $remoteJobId): void
    {
        if ($remoteJobId === '') {
            return;
        }

        try {
            $this->client()->post("/jobs/{$remoteJobId}/cancel")->throw();
        } catch (RequestException) {
        }
    }

    private function client()
    {
        $baseUrl = rtrim((string) config('services.runpod.base_url'), '/');
        $token = trim((string) config('services.runpod.api_token'));

        if ($baseUrl === '') {
            throw new RuntimeException('RunPod is enabled but RUNPOD_BASE_URL is missing.');
        }

        if ($token === '') {
            throw new RuntimeException('RunPod is enabled but RUNPOD_API_TOKEN is missing.');
        }

        return $this->http
            ->baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.runpod.timeout_seconds', 7200));
    }

    private function resolveImageUrl(ScanImage $image): string
    {
        $storedPath = (string) ($image->path_original ?: ScanObjectKeys::imageForSlot($image->scan_id, (int) $image->slot));
        $signed = $this->objectStorage->temporaryUrlIfAvailable($storedPath, 120);

        if (! $signed) {
            throw new RuntimeException("RunPod submission failed: could not create signed URL for slot {$image->slot}.");
        }

        return $signed;
    }
}
