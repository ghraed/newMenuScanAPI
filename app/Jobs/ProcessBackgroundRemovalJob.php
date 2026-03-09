<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\ScanImage;
use App\Services\MaskService;
use App\Services\ObjectStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class ProcessBackgroundRemovalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $jobId)
    {
    }

    public function handle(MaskService $maskService, ObjectStorageService $objectStorage): void
    {
        $job = Job::query()->with(['scan', 'scan.scanImages'])->find($this->jobId);

        if (! $job || ! $job->scan) {
            return;
        }

        $workdir = $this->createWorkdir($job->id);

        /** @var Collection<int, ScanImage> $images */
        $images = $job->scan->scanImages->sortBy('slot')->values();
        $totalImages = $images->count();
        $selection = $job->meta['objectSelection'] ?? null;

        if ($totalImages === 0) {
            $job->update([
                'status' => 'error',
                'progress' => 0,
                'message' => 'No uploaded images to preprocess.',
            ]);

            return;
        }

        try {
            $job->update([
                'status' => 'processing',
                'progress' => 0.05,
                'message' => 'Preparing background removal',
            ]);

            $previewImages = $this->pickPreviewImages($images);
            $previewCount = max(1, $previewImages->count());

            foreach ($previewImages as $index => $image) {
                $previewNumber = $index + 1;
                $rgba = $maskService->generateRgba($image, [
                    'selection' => $selection,
                    'mode' => 'preview',
                    'workdir' => $workdir,
                    'output_path' => "{$workdir}/preview/{$image->slot}.png",
                ]);

                $image->update([
                    'path_mask' => $rgba['key'],
                ]);

                $job->update([
                    'status' => 'partial',
                    'progress' => round(0.10 + (($previewNumber / $previewCount) * 0.30), 3),
                    'message' => $index === 0
                        ? 'Preview ready for slot '.((int) $image->slot + 1)
                        : "Generating previews ({$previewNumber}/{$previewCount})",
                ]);
            }

            foreach ($images as $index => $image) {
                $previewKey = $image->path_mask;
                $rgba = $maskService->generateRgba($image, [
                    'selection' => $selection,
                    'mode' => 'final',
                    'workdir' => $workdir,
                    'output_path' => "{$workdir}/processed/{$image->slot}.png",
                ]);

                $image->update([
                    'path_mask' => null,
                    'path_rgba' => $rgba['key'],
                ]);

                if ($previewKey) {
                    $objectStorage->delete($previewKey);
                }

                $processed = $index + 1;
                $job->update([
                    'status' => 'partial',
                    'progress' => round(0.45 + (($processed / $totalImages) * 0.50), 3),
                    'message' => "Improving quality ({$processed}/{$totalImages})",
                ]);
            }

            $job->update([
                'status' => 'ready',
                'progress' => 1.0,
                'message' => 'Background removal completed',
            ]);
        } catch (Throwable $error) {
            $job->update([
                'status' => 'error',
                'progress' => $job->progress ?? 0,
                'message' => $error->getMessage(),
            ]);
        } finally {
            File::deleteDirectory($workdir);
        }
    }

    /**
     * @param Collection<int, ScanImage> $images
     * @return Collection<int, ScanImage>
     */
    private function pickPreviewImages(Collection $images): Collection
    {
        $count = $images->count();

        if ($count <= 3) {
            return $images->values();
        }

        $indexes = collect([
            0,
            (int) floor(($count - 1) / 2),
            $count - 1,
        ])->unique()->values();

        return $indexes->map(fn (int $index) => $images->get($index))
            ->filter(fn ($image) => $image instanceof ScanImage)
            ->values();
    }

    private function createWorkdir(string $jobId): string
    {
        $configured = rtrim((string) env('PIPELINE_WORKDIR', storage_path('app/pipeline')), '/');
        $baseDir = str_starts_with($configured, '/')
            ? $configured
            : base_path($configured);
        $workdir = "{$baseDir}/background-{$jobId}";

        if (! is_dir($workdir) && ! mkdir($workdir, 0775, true) && ! is_dir($workdir)) {
            throw new RuntimeException('background removal failed: could not create pipeline workdir');
        }

        return $workdir;
    }
}
