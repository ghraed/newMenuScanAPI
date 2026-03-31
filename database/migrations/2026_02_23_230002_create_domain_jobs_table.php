<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scan_jobs')) {
            return;
        }

        Schema::create('scan_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scan_id');
            $table->string('type')->default('model');
            $table->string('status')->default('queued');
            $table->decimal('progress', 4, 3)->default(0);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('scan_id');
            $table->index(['scan_id', 'type']);
            $table->foreign('scan_id')->references('id')->on('scans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_jobs');
    }
};
