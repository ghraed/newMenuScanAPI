<?php

namespace Tests\Feature;

use App\Jobs\ProcessBackgroundRemovalJob;
use App\Models\Dish;
use App\Models\Job;
use App\Models\Scan;
use App\Models\ScanImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        [, $restaurant, $token] = $this->createRestaurantAuthContext();

        $scan = Scan::query()->create([
            'device_id' => 'device-1',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
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

        $response = $this->postJson(
            "/api/scans/{$scan->id}/preprocess-bg",
            [
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
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('availableSlots', [])
            ->assertJsonPath('previewAvailable', false);

        $this->assertDatabaseHas('scan_jobs', [
            'scan_id' => $scan->id,
            'type' => 'background',
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessBackgroundRemovalJob::class);
    }

    public function test_background_job_status_includes_available_slots(): void
    {
        [, $restaurant, $token] = $this->createRestaurantAuthContext();

        $scan = Scan::query()->create([
            'device_id' => 'device-2',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
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
            'path_mask' => "scans/{$scan->id}/processed/previews/0.png",
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

        $this->getJson("/api/jobs/{$job->id}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('previewAvailable', true)
            ->assertJsonPath('availableSlots', [0]);
    }

    public function test_submit_rejects_scans_without_an_attached_dish(): void
    {
        [, $restaurant, $token] = $this->createRestaurantAuthContext();

        $scan = Scan::query()->create([
            'device_id' => 'device-submit-1',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'uploaded',
        ]);

        $this->postJson("/api/scans/{$scan->id}/submit", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select or create a dish before generating the 3D model.');
    }

    public function test_scan_can_attach_an_existing_restaurant_dish(): void
    {
        [, $restaurant, $token] = $this->createRestaurantAuthContext();

        $scan = Scan::query()->create([
            'device_id' => 'device-attach-1',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'draft',
        ]);

        $dish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Attached Dish',
            'description' => 'Created in test',
            'price' => 15.50,
            'category' => 'Main',
            'status' => 'draft',
        ]);

        $this->patchJson(
            "/api/scans/{$scan->id}/attach-dish",
            ['dishId' => $dish->id],
            ['Authorization' => "Bearer {$token}"]
        )
            ->assertOk()
            ->assertJsonPath('scanId', $scan->id)
            ->assertJsonPath('dishId', $dish->id);

        $this->assertDatabaseHas('scans', [
            'id' => $scan->id,
            'dish_id' => $dish->id,
        ]);
    }
}
