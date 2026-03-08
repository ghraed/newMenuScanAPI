<?php

namespace Tests\Feature;

use App\Jobs\ProcessBackgroundRemovalJob;
use App\Models\Job;
use App\Models\Scan;
use App\Models\ScanImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackgroundRemovalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();
    }

    public function test_it_starts_background_removal_as_a_job(): void
    {
        Queue::fake();

        $scan = Scan::query()->create([
            'device_id' => 'device-1',
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'uploaded',
        ]);

        ScanImage::query()->create([
            'scan_id' => $scan->id,
            'slot' => 0,
            'heading' => 0,
            'path_original' => "scans/{$scan->id}/images/0.jpg",
        ]);

        $response = $this->postJson("/api/scans/{$scan->id}/preprocess-bg", [
            'objectSelection' => [
                'method' => 'box',
                'selectedAt' => 123456789,
                'bbox' => [
                    'x' => 0.2,
                    'y' => 0.2,
                    'width' => 0.4,
                    'height' => 0.4,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('availableSlots', [])
            ->assertJsonPath('previewAvailable', false);

        $this->assertDatabaseHas('jobs', [
            'scan_id' => $scan->id,
            'type' => 'background',
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessBackgroundRemovalJob::class);
    }

    public function test_background_job_status_includes_available_slots(): void
    {
        $scan = Scan::query()->create([
            'device_id' => 'device-2',
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'uploaded',
        ]);

        ScanImage::query()->create([
            'scan_id' => $scan->id,
            'slot' => 0,
            'heading' => 0,
            'path_original' => "scans/{$scan->id}/images/0.jpg",
            'path_rgba' => "scans/{$scan->id}/rgba/0.png",
        ]);

        ScanImage::query()->create([
            'scan_id' => $scan->id,
            'slot' => 5,
            'heading' => 90,
            'path_original' => "scans/{$scan->id}/images/5.jpg",
        ]);

        $job = Job::query()->create([
            'scan_id' => $scan->id,
            'type' => 'background',
            'status' => 'partial',
            'progress' => 0.5,
            'message' => 'Preview ready',
        ]);

        $this->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('previewAvailable', true)
            ->assertJsonPath('availableSlots', [0]);
    }
}
