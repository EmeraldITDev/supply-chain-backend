<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_trips')) {
            return;
        }

        Schema::table('logistics_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_trips', 'external_driver')) {
                $table->json('external_driver')->nullable()->after('driver_user_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('logistics_trips')) {
            return;
        }

        Schema::table('logistics_trips', function (Blueprint $table) {
            if (Schema::hasColumn('logistics_trips', 'external_driver')) {
                $table->dropColumn('external_driver');
            }
        });
    }
};
