<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Services\ScanAssetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanAssetSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['object-storage.disk' => 'b2']);
        Storage::fake('b2');
    }

    public function test_repeating_a_scan_keeps_the_new_model_file(): void
    {
        $dish = $this->dish();
        $scanId = (string) Str::uuid();
        $path = "scans/{$scanId}/outputs/model.glb";
        $service = app(ScanAssetSyncService::class);

        Storage::disk('b2')->put($path, 'first model');
        $service->syncDishAssets($dish->id, $scanId, $path, null, null);
        $oldAssetId = DishAsset::query()->where('dish_id', $dish->id)->firstOrFail()->id;

        Storage::disk('b2')->put($path, 'second model');
        $service->syncDishAssets($dish->id, $scanId, $path, null, null);

        $this->assertSame('second model', Storage::disk('b2')->get($path));
        $this->assertDatabaseMissing('dish_assets', ['id' => $oldAssetId]);
        $this->assertDatabaseCount('dish_assets', 1);
        $this->assertDatabaseHas('dish_assets', ['dish_id' => $dish->id, 'asset_type' => 'glb', 'file_path' => $path]);
    }

    public function test_new_glb_without_usdz_removes_stale_dish_assets_but_preserves_scan_outputs(): void
    {
        $dish = $this->dish();
        $oldScanId = (string) Str::uuid();
        $newScanId = (string) Str::uuid();
        $oldGlb = "scans/{$oldScanId}/outputs/model.glb";
        $oldUsdz = "scans/{$oldScanId}/outputs/model.usdz";
        $newGlb = "scans/{$newScanId}/outputs/model.glb";

        foreach ([$oldGlb, $oldUsdz, $newGlb] as $path) {
            Storage::disk('b2')->put($path, 'model bytes');
        }

        $service = app(ScanAssetSyncService::class);
        $service->syncDishAssets($dish->id, $oldScanId, $oldGlb, $oldUsdz, null);
        $service->syncDishAssets($dish->id, $newScanId, $newGlb, null, null);

        $this->assertDatabaseCount('dish_assets', 1);
        $this->assertDatabaseHas('dish_assets', ['dish_id' => $dish->id, 'asset_type' => 'glb', 'file_path' => $newGlb]);
        $this->assertDatabaseMissing('dish_assets', ['dish_id' => $dish->id, 'asset_type' => 'usdz']);
        Storage::disk('b2')->assertExists([$oldGlb, $oldUsdz, $newGlb]);
    }

    public function test_replacing_a_manual_model_still_cleans_up_its_unreferenced_file(): void
    {
        $dish = $this->dish();
        $oldPath = "dishes/{$dish->id}/old-model.glb";
        $newScanId = (string) Str::uuid();
        $newPath = "scans/{$newScanId}/outputs/model.glb";
        Storage::disk('b2')->put($oldPath, 'old model');
        Storage::disk('b2')->put($newPath, 'new model');

        DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => 'glb',
            'storage_disk' => 'b2',
            'file_path' => $oldPath,
            'file_url' => '/api/assets/old/file',
        ]);

        app(ScanAssetSyncService::class)->syncDishAssets($dish->id, $newScanId, $newPath, null, null);

        Storage::disk('b2')->assertMissing($oldPath);
        Storage::disk('b2')->assertExists($newPath);
        $this->assertDatabaseHas('dish_assets', ['dish_id' => $dish->id, 'file_path' => $newPath]);
    }

    private function dish(): Dish
    {
        [, $restaurant] = $this->createRestaurantAuthContext();

        return Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'QA_RUN model dish',
            'price' => 1,
        ]);
    }
}
