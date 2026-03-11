<?php

namespace Tests\Unit;

use App\Services\MaskService;
use App\Services\ObjectStorageService;
use ReflectionMethod;
use Tests\TestCase;

class MaskServiceTest extends TestCase
{
    public function test_final_mode_uses_original_image_without_preprocessing(): void
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

        $this->assertSame($inputPath, $result['path']);
        $this->assertIsArray($result['selectionContext']);
        $this->assertSame(2400, $result['selectionContext']['preparedWidth']);
        $this->assertSame(1600, $result['selectionContext']['preparedHeight']);

        @unlink($inputPath);
    }
}
