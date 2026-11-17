<?php

namespace App\Services;

use App\Models\DishAsset;
use App\Models\JobOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ScanAssetSyncService
{
    public function syncDishAssets(
        int $dishId,
        string $scanId,
        string $glbPath,
        ?string $usdzPath,
        ?string $previewPath,
        ?string $glbLocalPath = null,
        ?string $usdzLocalPath = null,
        ?string $previewLocalPath = null,
        ?string $storageDisk = null,
    ): void {
        if ($dishId <= 0) {
            return;
        }

        $resolvedStorageDisk = $storageDisk ?: (string) config('object-storage.disk', 'b2');

        $oldAssets = DB::transaction(function () use (
            $dishId, $scanId, $glbPath, $usdzPath, $previewPath,
            $glbLocalPath, $usdzLocalPath, $previewLocalPath, $resolvedStorageDisk
        ): array {
            $oldAssets = [];

            $this->replaceDishAsset(
                dishId: $dishId,
                assetType: 'glb',
                storageDisk: $resolvedStorageDisk,
                storedPath: $glbPath,
                mimeType: 'model/gltf-binary',
                localPath: $glbLocalPath,
                scanId: $scanId,
                oldAssets: $oldAssets,
            );

            $this->replaceDishAsset(
                dishId: $dishId,
                assetType: 'usdz',
                storageDisk: $resolvedStorageDisk,
                storedPath: $usdzPath,
                mimeType: 'model/vnd.usdz+zip',
                localPath: $usdzLocalPath,
                scanId: $scanId,
                oldAssets: $oldAssets,
            );

            $this->replaceDishAsset(
                dishId: $dishId,
                assetType: 'preview_image',
                storageDisk: $resolvedStorageDisk,
                storedPath: $previewPath,
                mimeType: 'image/jpeg',
                localPath: $previewLocalPath,
                scanId: $scanId,
                oldAssets: $oldAssets,
            );

            return $oldAssets;
        });

        foreach ($oldAssets as $asset) {
            $this->deleteUnreferencedAssetFile($asset);
        }
    }

    private function replaceDishAsset(
        int $dishId,
        string $assetType,
        string $storageDisk,
        ?string $storedPath,
        string $mimeType,
        ?string $localPath,
        string $scanId,
        array &$oldAssets
    ): void {
        DishAsset::query()
            ->where('dish_id', $dishId)
            ->where('asset_type', $assetType)
            ->get()
            ->each(function (DishAsset $existingAsset) use (&$oldAssets): void {
                $oldAssets[] = $existingAsset;
                $existingAsset->delete();
            });

        if ($storedPath === null || $storedPath === '') {
            return;
        }

        $asset = DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dishId,
            'asset_type' => $assetType,
            'storage_disk' => $storageDisk,
            'file_path' => $storedPath,
            'glb_path' => $assetType === 'glb' ? $storedPath : null,
            'usdz_path' => $assetType === 'usdz' ? $storedPath : null,
            'file_url' => '',
            'file_size' => $this->resolveLocalFileSize($localPath),
            'mime_type' => $mimeType,
            'metadata' => [
                'source' => 'scan_pipeline',
                'scan_id' => $scanId,
                'uploaded_at' => now()->toIso8601String(),
            ],
        ]);

        $asset->update([
            'file_url' => $this->buildMenuAssetUrl((int) $asset->id),
        ]);
    }

    private function deleteUnreferencedAssetFile(DishAsset $asset): void
    {
        if (! $asset->file_path) {
            return;
        }

        $disk = $asset->storage_disk ?: 'public';

        // Scan outputs remain available through job download URLs, even after
        // the dish switches to a newer model.
        if (str_starts_with($asset->file_path, 'scans/')
            || DishAsset::query()->where('storage_disk', $disk)->where('file_path', $asset->file_path)->exists()
            || JobOutput::query()->where('glb_path', $asset->file_path)
                ->orWhere('usdz_path', $asset->file_path)
                ->orWhere('preview_path', $asset->file_path)
                ->orWhere('obj_path', $asset->file_path)->exists()) {
            return;
        }

        try {
            Storage::disk($disk)->delete($asset->file_path);
        } catch (Throwable) {
        }
    }

    private function buildMenuAssetUrl(int $assetId): string
    {
        $baseUrl = rtrim((string) env('MENU_API_URL', ''), '/');
        $path = "/api/assets/{$assetId}/file";

        return $baseUrl === '' ? $path : $baseUrl.$path;
    }

    private function resolveLocalFileSize(?string $localPath): ?int
    {
        if (! $localPath || ! is_file($localPath)) {
            return null;
        }

        $size = @filesize($localPath);

        return is_int($size) ? $size : null;
    }
}
