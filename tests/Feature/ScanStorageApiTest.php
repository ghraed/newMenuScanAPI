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
        $scan = Scan::query()->create([
            'device_id' => 'device-storage-1',
            'target_type' => 'dish',
            'scale_meters' => 0.24,
            'slots_total' => 24,
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/scans/{$scan->id}/images", [
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
        $scan = Scan::query()->create([
            'device_id' => 'device-storage-2',
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
            'preview_path' => "scans/{$scan->id}/outputs/preview.jpg",
        ]);

        Storage::disk('b2')->put($output->glb_path, 'glb-data');
        Storage::disk('b2')->put($output->preview_path, 'preview-data');

        $this->getJson("/api/jobs/{$job->id}")
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
                'outputs.previewSignedUrl',
                "https://signed.example/scans/{$scan->id}/outputs/preview.jpg"
            );

        $this->get("/api/files/{$scan->id}/glb")
            ->assertRedirect("https://signed.example/scans/{$scan->id}/outputs/model.glb");
    }
}
