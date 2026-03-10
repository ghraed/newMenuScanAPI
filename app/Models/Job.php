<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Job extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELED = 'canceled';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'scan_id',
        'type',
        'status',
        'progress',
        'message',
        'meta',
    ];

    protected $casts = [
        'progress' => 'float',
        'meta' => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function jobOutput(): HasOne
    {
        return $this->hasOne(JobOutput::class);
    }

    public function freshStatus(): ?string
    {
        return static::query()->whereKey($this->getKey())->value('status');
    }

    public function isCanceled(): bool
    {
        return $this->freshStatus() === self::STATUS_CANCELED;
    }
}
