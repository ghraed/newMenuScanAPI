<?php

namespace Tests;

use App\Models\PersonalAccessToken;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $storagePath = sys_get_temp_dir().'/menu-app-api-tests';

        if (! is_dir($storagePath)) {
            mkdir($storagePath, 0775, true);
        }

        $this->app->useStoragePath($storagePath);

        config([
            'filesystems.disks.local.root' => storage_path('app/private'),
            'filesystems.disks.public.root' => storage_path('app/public'),
        ]);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Restaurant, 2: string}
     */
    protected function createRestaurantAuthContext(): array
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant-'.Str::lower(Str::random(6)),
        ]);

        $plainTextToken = 'scanner-test-token-'.Str::random(20);
        $token = PersonalAccessToken::query()->create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test-token',
            'token' => hash('sha256', $plainTextToken),
            'abilities' => ['*'],
        ]);

        return [$user, $restaurant, $token->id.'|'.$plainTextToken];
    }
}
