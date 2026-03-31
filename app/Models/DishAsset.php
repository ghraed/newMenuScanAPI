<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DishAsset extends Model
{
    protected $fillable = [
        'uuid',
        'dish_id',
        'asset_type',
        'storage_disk',
        'file_path',
        'glb_path',
        'usdz_path',
        'file_url',
        'file_size',
        'mime_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
    ];

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
