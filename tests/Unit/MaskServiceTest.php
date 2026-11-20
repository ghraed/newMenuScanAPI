<?php

namespace Tests\Unit;

use App\Services\MaskService;
use App\Services\ObjectStorageService;
use ReflectionMethod;
use Tests\TestCase;

class MaskServiceTest extends TestCase
{
    public function test_gray_and_black_edge_background_becomes_transparent_without_erasing_dark_subject(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mask-neutral-');
        $image = imagecreatetruecolor(9, 9);
        $gray = imagecolorallocate($image, 110, 110, 110);
        $black = imagecolorallocate($image, 5, 5, 5);
        $red = imagecolorallocate($image, 190, 50, 35);
        imagefill($image, 0, 0, $gray);
        imagefilledrectangle($image, 0, 0, 0, 8, $black);
        imagefilledrectangle($image, 2, 2, 6, 6, $red);
        imagefilledrectangle($image, 3, 3, 5, 5, $black);
        imagepng($image, $path);
        imagedestroy($image);

        try {
            $service = new MaskService($this->createMock(ObjectStorageService::class));
            $method = new ReflectionMethod(MaskService::class, 'clearEdgeConnectedNeutralBackground');
            $method->invoke($service, $path);

            $result = imagecreatefrompng($path);
            $this->assertSame(127, (imagecolorat($result, 0, 4) >> 24) & 0x7F);
            $this->assertSame(127, (imagecolorat($result, 1, 4) >> 24) & 0x7F);
            $this->assertSame(0, (imagecolorat($result, 4, 4) >> 24) & 0x7F);
            $this->assertSame(0, (imagecolorat($result, 2, 4) >> 24) & 0x7F);
            imagedestroy($result);
        } finally {
            @unlink($path);
        }
    }

    public function test_final_mode_skips_selection_refinement_when_orientation_is_already_normalized(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $service = new MaskService($this->createMock(ObjectStorageService::class));
        $inputPath = tempnam(sys_get_temp_dir(), 'mask-final-');

        $image = imagecreatetruecolor(2400, 1600);
        $background = imagecolorallocate($image, 220, 110, 40);
        imagefill($image, 0, 0, $background);
        imagejpeg($image, $inputPath, 95);
        imagedestroy($image);

        $method = new ReflectionMethod(MaskService::class, 'prepareInputImage');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            0,
            $inputPath,
            sys_get_temp_dir().'/mask-service-test',
            [
                'method' => 'box',
                'bbox' => [
                    'x' => 0.2,
                    'y' => 0.25,
                    'width' => 0.4,
                    'height' => 0.3,
                ],
            ],
            'final',
        );

        $this->assertSame(sys_get_temp_dir().'/mask-service-test/slot-0-final.jpg', $result['path']);
        $this->assertFileExists($result['path']);
        $this->assertSame([2200, 1467], array_slice(getimagesize($result['path']), 0, 2));
        $this->assertNull($result['selectionContext']);

        @unlink($result['path']);
        @unlink($inputPath);
    }
}
