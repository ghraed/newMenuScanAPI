<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Scan;
use App\Models\ScanImage;
use App\Services\RunPodPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunPodBackgroundReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_background_images_are_sent_to_worker_without_reprocessing(): void
    {
        $this->setUpWorker();
        $job = $this->createModelJob();

        app(RunPodPipelineService::class)->submitJob($job);

        Http::assertSent(fn ($request) => $request['options']['removeBackground'] === false
            && $request['images'][0]['url'] === 'https://signed.example/processed/0.png'
            && $request['images'][1]['url'] === 'https://signed.example/processed/1.png');
    }

    public function test_missing_background_image_falls_back_to_originals_and_worker_removal(): void
    {
        $this->setUpWorker();
        $job = $this->createModelJob();
        Storage::disk('b2')->delete('processed/1.png');

        app(RunPodPipelineService::class)->submitJob($job);

        Http::assertSent(fn ($request) => $request['options']['removeBackground'] === true
            && $request['images'][0]['url'] === 'https://signed.example/original/0.jpg'
            && $request['images'][1]['url'] === 'https://signed.example/original/1.jpg');
    }

    private function setUpWorker(): void
    {
        config([
            'object-storage.disk' => 'b2',
            'services.runpod.base_url' => 'https://worker.example',
            'services.runpod.api_token' => 'test-token',
            'services.runpod.callback_token' => 'test-callback',
        ]);
        putenv('B2_BUCKET=test-bucket');
        putenv('B2_ENDPOINT=https://b2.example');
        putenv('B2_REGION=test-region');
        Storage::fake('b2');
        Storage::disk('b2')->buildTemporaryUrlsUsing(
            fn (string $path) => "https://signed.example/{$path}"
        );
        Http::fake(['worker.example/*' => Http::response(['jobId' => 'remote-job', 'status' => 'queued'])]);
    }

    private function createModelJob(): Job
    {
        [, $restaurant] = $this->createRestaurantAuthContext();
        $scan = Scan::query()->create([
            'device_id' => 'test-device',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 2,
            'status' => 'uploaded',
        ]);

        foreach ([0, 1] as $slot) {
            Storage::disk('b2')->put("original/{$slot}.jpg", 'original');
            Storage::disk('b2')->put("processed/{$slot}.png", 'processed');
            ScanImage::query()->create([
                'scan_id' => $scan->id,
                'slot' => $slot,
                'heading' => $slot * 180,
                'path_original' => "original/{$slot}.jpg",
                'path_rgba' => "processed/{$slot}.png",
            ]);
        }

        Job::query()->create([
            'scan_id' => $scan->id,
            'type' => 'background',
            'status' => Job::STATUS_READY,
        ]);

        return Job::query()->create([
            'scan_id' => $scan->id,
            'type' => 'model',
            'status' => Job::STATUS_QUEUED,
        ]);
    }
}
