<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ObjectStorageService
{
    public function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('object-storage.disk', 'b2');
    }

    public function uploadFile(string $key, string $localPath, array $options = []): string
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("object storage upload failed: unable to read {$localPath}");
        }

        try {
            $stored = $this->disk()->put($key, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored === false) {
            throw new RuntimeException("object storage upload failed for {$key}");
        }

        return $key;
    }

    public function uploadContent(string $key, string $contents, array $options = []): string
    {
        if ($this->disk()->put($key, $contents, $options) === false) {
            throw new RuntimeException("object storage upload failed for {$key}");
        }

        return $key;
    }

    public function downloadToPath(string $key, string $localPath): string
    {
        $stream = $this->disk()->readStream($key);

        if ($stream === false) {
            throw new RuntimeException("object storage download failed for {$key}");
        }

        $directory = dirname($localPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new RuntimeException("object storage download failed: could not create {$directory}");
        }

        $target = @fopen($localPath, 'wb');

        if ($target === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new RuntimeException("object storage download failed: unable to write {$localPath}");
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $localPath;
    }

    public function downloadStoredPathTo(string $storedPath, string $localPath): string
    {
        if (str_starts_with($storedPath, '/')) {
            return $this->copyLocalFile($storedPath, $localPath);
        }

        // New pipeline writes B2 object keys, but we still fall back to local files for legacy rows.
        if ($this->exists($storedPath)) {
            return $this->downloadToPath($storedPath, $localPath);
        }

        if (Storage::disk('local')->exists($storedPath)) {
            return $this->copyLocalFile(Storage::disk('local')->path($storedPath), $localPath);
        }

        throw new RuntimeException("stored file not found: {$storedPath}");
    }

    public function exists(string $key): bool
    {
        try {
            return $this->disk()->exists($key);
        } catch (Throwable) {
            return false;
        }
    }

    public function temporaryUrl(
        string $key,
        DateTimeInterface|int|null $expiresAt = null,
        array $options = []
    ): string {
        $expiration = $expiresAt instanceof DateTimeInterface
            ? $expiresAt
            : now()->addMinutes($expiresAt ?? (int) config('object-storage.signed_url_ttl_minutes', 15));

        return $this->disk()->temporaryUrl($key, $expiration, $options);
    }

    public function temporaryUrlIfAvailable(
        ?string $key,
        DateTimeInterface|int|null $expiresAt = null,
        array $options = []
    ): ?string {
        if (! is_string($key) || $key === '' || str_starts_with($key, '/')) {
            return null;
        }

        try {
            if (! $this->exists($key)) {
                return null;
            }

            return $this->temporaryUrl($key, $expiresAt, $options);
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string|array $keys): bool
    {
        try {
            return $this->disk()->delete($keys);
        } catch (Throwable) {
            return false;
        }
    }

    private function copyLocalFile(string $sourcePath, string $targetPath): string
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("stored file not found: {$sourcePath}");
        }

        $directory = dirname($targetPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("failed to create local temp directory: {$directory}");
        }

        if (! copy($sourcePath, $targetPath)) {
            throw new RuntimeException("failed to copy stored file to {$targetPath}");
        }

        return $targetPath;
    }
}
