<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scan_job_outputs')) {
            return;
        }

        Schema::create('scan_job_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->string('glb_path')->nullable();
            $table->string('usdz_path')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('obj_path')->nullable();
            $table->timestamps();

            $table->index('job_id');
            $table->unique('job_id');
            $table->foreign('job_id')->references('id')->on('scan_jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_job_outputs');
    }
};
