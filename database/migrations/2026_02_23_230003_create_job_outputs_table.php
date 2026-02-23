<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->string('glb_path')->nullable();
            $table->string('usdz_path')->nullable();
            $table->timestamps();

            $table->index('job_id');
            $table->foreign('job_id')->references('id')->on('jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_outputs');
    }
};
