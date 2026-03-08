<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('type')->default('model')->after('scan_id');
            $table->json('meta')->nullable()->after('message');
            $table->index(['scan_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['scan_id', 'type']);
            $table->dropColumn(['type', 'meta']);
        });
    }
};
