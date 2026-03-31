<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs') && ! Schema::hasTable('scan_jobs')) {
            Schema::rename('jobs', 'scan_jobs');
        }

        if (Schema::hasTable('job_outputs') && ! Schema::hasTable('scan_job_outputs')) {
            Schema::rename('job_outputs', 'scan_job_outputs');
        }

        if (Schema::hasTable('scans')) {
            Schema::table('scans', function (Blueprint $table) {
                if (! Schema::hasColumn('scans', 'restaurant_id')) {
                    $table->foreignId('restaurant_id')->nullable()->after('device_id')->constrained()->cascadeOnDelete();
                }
                if (! Schema::hasColumn('scans', 'created_by_user_id')) {
                    $table->foreignId('created_by_user_id')->nullable()->after('restaurant_id')->constrained('users')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('scans', 'dish_id')) {
                    $table->foreignId('dish_id')->nullable()->after('created_by_user_id')->constrained()->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        //
    }
};
