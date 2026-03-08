<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_outputs', function (Blueprint $table) {
            $table->string('preview_path')->nullable()->after('usdz_path');
            $table->string('obj_path')->nullable()->after('preview_path');
        });
    }

    public function down(): void
    {
        Schema::table('job_outputs', function (Blueprint $table) {
            $table->dropColumn(['preview_path', 'obj_path']);
        });
    }
};
