<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('logistics_trips') && ! Schema::hasColumn('logistics_trips', 'external_passengers')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                $table->json('external_passengers')->nullable()->after('passenger_user_ids');
            });
        }

        if (Schema::hasTable('s_r_f_s') && ! Schema::hasColumn('s_r_f_s', 'payment_milestones')) {
            Schema::table('s_r_f_s', function (Blueprint $table) {
                $table->json('payment_milestones')->nullable()->after('rfq_prefill');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('logistics_trips') && Schema::hasColumn('logistics_trips', 'external_passengers')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                $table->dropColumn('external_passengers');
            });
        }

        if (Schema::hasTable('s_r_f_s') && Schema::hasColumn('s_r_f_s', 'payment_milestones')) {
            Schema::table('s_r_f_s', function (Blueprint $table) {
                $table->dropColumn('payment_milestones');
            });
        }
    }
};
