<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scan_id');
            $table->integer('slot');
            $table->decimal('heading', 8, 3);
            $table->string('path_original');
            $table->string('path_mask')->nullable();
            $table->string('path_rgba')->nullable();
            $table->timestamps();

            $table->index('scan_id');
            $table->unique(['scan_id', 'slot']);
            $table->foreign('scan_id')->references('id')->on('scans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_images');
    }
};
