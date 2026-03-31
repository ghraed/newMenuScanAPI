<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scans')) {
            return;
        }

        Schema::create('scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('device_id')->nullable();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('dish_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type');
            $table->decimal('scale_meters', 8, 3)->default(0.240);
            $table->integer('slots_total')->default(24);
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->index(['restaurant_id', 'dish_id']);
            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
