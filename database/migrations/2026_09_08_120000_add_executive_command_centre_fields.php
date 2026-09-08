<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'po_value')) {
                $table->decimal('po_value', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'scd_approved_at')) {
                $table->timestamp('scd_approved_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'procurement_approved_at')) {
                $table->timestamp('procurement_approved_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'finance_approved_at')) {
                $table->timestamp('finance_approved_at')->nullable();
            }
        });

        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'completed_orders')) {
                $table->unsignedInteger('completed_orders')->default(0);
            }
            if (! Schema::hasColumn('vendors', 'on_time_deliveries')) {
                $table->unsignedInteger('on_time_deliveries')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $drop = [];
            foreach (['po_value', 'scd_approved_at', 'procurement_approved_at', 'finance_approved_at'] as $col) {
                if (Schema::hasColumn('m_r_f_s', $col)) {
                    $drop[] = $col;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('vendors', function (Blueprint $table) {
            $drop = [];
            foreach (['completed_orders', 'on_time_deliveries'] as $col) {
                if (Schema::hasColumn('vendors', $col)) {
                    $drop[] = $col;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
