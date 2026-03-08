<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
