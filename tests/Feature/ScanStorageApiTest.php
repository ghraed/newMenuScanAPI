<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\JobOutput;
use App\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScanStorageApiTest extends TestCase
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

        config([
            'object-storage.disk' => 'b2',
            'object-storage.signed_url_ttl_minutes' => 15,
        ]);

        Storage::fake('b2');
        Storage::disk('b2')->buildTemporaryUrlsUsing(
            fn (string $path, mixed $expiration = null, array $options = []) => "https://signed.example/{$path}"
        );
    }

    public function test_scan_image_uploads_are_stored_on_b2_and_persisted_as_object_keys(): void
    {
        [, $restaurant, $token] = $this->createRestaurantAuthContext();

        $scan = Scan::query()->create([
            'device_id' => 'device-storage-1',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'draft',
        ]);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
        ])->post("/api/scans/{$scan->id}/images", [
            'slot' => 0,
            'heading' => 15,
            'image' => UploadedFile::fake()->image('capture.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        $storedKey = "scans/{$scan->id}/images/0.jpg";

        Storage::disk('b2')->assertExists($storedKey);
        $this->assertDatabaseHas('scan_images', [
            'scan_id' => $scan->id,
            'slot' => 0,
            'path_original' => $storedKey,
        ]);
    }

    public function test_job_status_and_download_endpoint_expose_signed_b2_urls(): void
    {
        [, $restaurant, $token] = $this->createRestaurantAuthContext();

        $scan = Scan::query()->create([
            'device_id' => 'device-storage-2',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'ready',
        ]);

        $job = Job::query()->create([
            'scan_id' => $scan->id,
            'type' => 'model',
            'status' => 'ready',
            'progress' => 1,
        ]);

        $output = JobOutput::query()->create([
            'job_id' => $job->id,
            'glb_path' => "scans/{$scan->id}/outputs/model.glb",
            'usdz_path' => "scans/{$scan->id}/outputs/model.usdz",
            'preview_path' => "scans/{$scan->id}/outputs/preview.jpg",
        ]);

        Storage::disk('b2')->put($output->glb_path, 'glb-data');
        Storage::disk('b2')->put($output->usdz_path, 'usdz-data');
        Storage::disk('b2')->put($output->preview_path, 'preview-data');

        $this->getJson("/api/jobs/{$job->id}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath(
                'outputs.glbUrl',
                route('api.files.show', ['scanId' => $scan->id, 'type' => 'glb'])
            )
            ->assertJsonPath(
                'outputs.glbSignedUrl',
                "https://signed.example/scans/{$scan->id}/outputs/model.glb"
            )
            ->assertJsonPath(
                'outputs.usdzUrl',
                route('api.files.show', ['scanId' => $scan->id, 'type' => 'usdz'])
            )
            ->assertJsonPath(
                'outputs.usdzSignedUrl',
                "https://signed.example/scans/{$scan->id}/outputs/model.usdz"
            )
            ->assertJsonPath(
                'outputs.previewSignedUrl',
                "https://signed.example/scans/{$scan->id}/outputs/preview.jpg"
            );

        $this->get("/api/files/{$scan->id}/glb", ['Authorization' => "Bearer {$token}"])
            ->assertRedirect("https://signed.example/scans/{$scan->id}/outputs/model.glb");

        $this->get("/api/files/{$scan->id}/usdz", ['Authorization' => "Bearer {$token}"])
            ->assertRedirect("https://signed.example/scans/{$scan->id}/outputs/model.usdz");
    }

    public function test_job_status_can_return_ready_glb_without_usdz_output(): void
    {
        [, $restaurant, $token] = $this->createRestaurantAuthContext();

        $scan = Scan::query()->create([
            'device_id' => 'device-storage-3',
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $restaurant->user_id,
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'ready',
        ]);

        $job = Job::query()->create([
            'scan_id' => $scan->id,
            'type' => 'model',
            'status' => 'ready',
            'progress' => 1,
            'message' => '3D model ready. USDZ export unavailable: missing converter.',
        ]);

        $output = JobOutput::query()->create([
            'job_id' => $job->id,
            'glb_path' => "scans/{$scan->id}/outputs/model.glb",
            'preview_path' => "scans/{$scan->id}/outputs/preview.jpg",
        ]);

        Storage::disk('b2')->put($output->glb_path, 'glb-data');
        Storage::disk('b2')->put($output->preview_path, 'preview-data');

        $this->getJson("/api/jobs/{$job->id}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('outputs.glbUrl', route('api.files.show', ['scanId' => $scan->id, 'type' => 'glb']))
            ->assertJsonMissingPath('outputs.usdzUrl')
            ->assertJsonPath('message', '3D model ready. USDZ export unavailable: missing converter.');
    }
}
