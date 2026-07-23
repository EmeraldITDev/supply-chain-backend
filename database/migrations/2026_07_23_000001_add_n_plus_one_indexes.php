<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'mrf_id')) {
                $table->index('mrf_id', 'quotations_mrf_id_idx');
            }
            if (Schema::hasColumn('quotations', 'vendor_id')) {
                $table->index('vendor_id', 'quotations_vendor_id_idx');
            }
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            if (Schema::hasColumn('quotation_items', 'quotation_id')) {
                $table->index('quotation_id', 'quotation_items_quotation_id_idx');
            }
        });

        Schema::table('r_f_q_s', function (Blueprint $table) {
            if (Schema::hasColumn('r_f_q_s', 'mrf_id')) {
                $table->index('mrf_id', 'rfqs_mrf_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['quotations_mrf_id_idx']);
            $table->dropIndex(['quotations_vendor_id_idx']);
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropIndex(['quotation_items_quotation_id_idx']);
        });

        Schema::table('r_f_q_s', function (Blueprint $table) {
            $table->dropIndex(['rfqs_mrf_id_idx']);
        });
    }
};
