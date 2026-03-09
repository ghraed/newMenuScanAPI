<?php

namespace App\Support;

class ScanObjectKeys
{
    public static function base(string $scanId): string
    {
        return "scans/{$scanId}";
    }

    public static function image(string $scanId, string $filename): string
    {
        return self::base($scanId)."/images/{$filename}";
    }

    public static function imageForSlot(string $scanId, int $slot): string
    {
        return self::image($scanId, "{$slot}.jpg");
    }

    public static function processed(string $scanId, string $filename): string
    {
        return self::base($scanId)."/processed/{$filename}";
    }

    public static function processedForSlot(string $scanId, int $slot): string
    {
        return self::processed($scanId, "{$slot}.png");
    }

    public static function processedPreview(string $scanId, string $filename): string
    {
        return self::base($scanId)."/processed/previews/{$filename}";
    }

    public static function processedPreviewForSlot(string $scanId, int $slot): string
    {
        return self::processedPreview($scanId, "{$slot}.png");
    }

    public static function output(string $scanId, string $filename): string
    {
        return self::base($scanId)."/outputs/{$filename}";
    }

    public static function modelGlb(string $scanId): string
    {
        return self::output($scanId, 'model.glb');
    }

    public static function modelUsdz(string $scanId): string
    {
        return self::output($scanId, 'model.usdz');
    }

    public static function previewImage(string $scanId): string
    {
        return self::output($scanId, 'preview.jpg');
    }

    public static function modelObj(string $scanId): string
    {
        return self::output($scanId, 'model.obj');
    }
}
