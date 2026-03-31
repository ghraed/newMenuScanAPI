<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dish_assets')) {
            return;
        }

        Schema::create('dish_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->enum('asset_type', ['usdz', 'glb', 'preview_image']);
            $table->string('storage_disk')->default('public');
            $table->string('file_path');
            $table->string('file_url');
            $table->string('glb_path')->nullable();
            $table->string('usdz_path')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['dish_id', 'asset_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_assets');
    }
};
