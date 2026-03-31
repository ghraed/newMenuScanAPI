<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dish extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'name',
        'description',
        'price',
        'category',
        'status',
        'image_url',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DishAsset::class);
    }
}
