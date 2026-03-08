<?php

namespace Tests\Unit;

use App\Services\ObjectStorageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ObjectStorageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'object-storage.disk' => 'b2',
            'object-storage.signed_url_ttl_minutes' => 15,
        ]);

        Storage::fake('b2');
        Storage::fake('local');
        Storage::disk('b2')->buildTemporaryUrlsUsing(
            fn (string $path, mixed $expiration = null, array $options = []) => "https://signed.example/{$path}"
        );
    }

    public function test_it_uploads_local_files_and_generates_signed_urls(): void
    {
        $service = app(ObjectStorageService::class);
        $tempFile = tempnam(sys_get_temp_dir(), 'b2-upload-');

        file_put_contents($tempFile, 'image-bytes');

        $key = $service->uploadFile('scans/scan-1/images/0.jpg', $tempFile, [
            'ContentType' => 'image/jpeg',
        ]);

        $this->assertSame('scans/scan-1/images/0.jpg', $key);
        Storage::disk('b2')->assertExists($key);
        $this->assertSame(
            'https://signed.example/scans/scan-1/images/0.jpg',
            $service->temporaryUrl($key)
        );

        @unlink($tempFile);
    }

    public function test_it_uploads_content_and_downloads_b2_and_legacy_local_paths(): void
    {
        $service = app(ObjectStorageService::class);
        $b2Key = $service->uploadContent('scans/scan-1/outputs/model.glb', 'glb-data', [
            'ContentType' => 'model/gltf-binary',
        ]);

        $b2DownloadPath = sys_get_temp_dir().'/object-storage-b2-download.bin';
        @unlink($b2DownloadPath);

        $service->downloadStoredPathTo($b2Key, $b2DownloadPath);

        $this->assertSame('glb-data', file_get_contents($b2DownloadPath));

        Storage::disk('local')->put('legacy/scan/file.txt', 'legacy-data');

        $legacyDownloadPath = sys_get_temp_dir().'/object-storage-legacy-download.txt';
        @unlink($legacyDownloadPath);

        $service->downloadStoredPathTo('legacy/scan/file.txt', $legacyDownloadPath);

        $this->assertSame('legacy-data', file_get_contents($legacyDownloadPath));

        @unlink($b2DownloadPath);
        @unlink($legacyDownloadPath);
    }
}
