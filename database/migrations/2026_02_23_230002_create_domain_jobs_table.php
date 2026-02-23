<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scan_id');
            $table->string('status')->default('queued');
            $table->decimal('progress', 4, 3)->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('scan_id');
            $table->foreign('scan_id')->references('id')->on('scans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
