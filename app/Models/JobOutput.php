<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOutput extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'job_id',
        'glb_path',
        'usdz_path',
        'preview_path',
        'obj_path',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * @return array<string, string>
     */
    public function availablePaths(): array
    {
        return array_filter([
            'glb' => $this->glb_path,
            'usdz' => $this->usdz_path,
            'preview' => $this->preview_path,
            'obj' => $this->obj_path,
        ], static fn (?string $path): bool => $path !== null && $path !== '');
    }

    public function pathForType(string $type): ?string
    {
        return match ($type) {
            'glb' => $this->glb_path,
            'usdz' => $this->usdz_path,
            'preview' => $this->preview_path,
            'obj' => $this->obj_path,
            default => null,
        };
    }
}
