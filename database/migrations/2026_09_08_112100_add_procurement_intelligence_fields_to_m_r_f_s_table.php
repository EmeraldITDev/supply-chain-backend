<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'expected_delivery_date')) {
                $table->date('expected_delivery_date')->nullable()->after('po_generated_at');
            }

            if (! Schema::hasColumn('m_r_f_s', 'rfq_issued_at')) {
                $after = Schema::hasColumn('m_r_f_s', 'po_generated_at')
                    ? 'po_generated_at'
                    : (Schema::hasColumn('m_r_f_s', 'expected_delivery_date') ? 'expected_delivery_date' : null);
                if ($after) {
                    $table->timestamp('rfq_issued_at')->nullable()->after($after);
                } else {
                    $table->timestamp('rfq_issued_at')->nullable();
                }
            }

            if (! Schema::hasColumn('m_r_f_s', 'quotation_received_at')) {
                if (Schema::hasColumn('m_r_f_s', 'rfq_issued_at')) {
                    $table->timestamp('quotation_received_at')->nullable()->after('rfq_issued_at');
                } else {
                    $table->timestamp('quotation_received_at')->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('m_r_f_s', 'quotation_received_at')) {
                $drop[] = 'quotation_received_at';
            }
            if (Schema::hasColumn('m_r_f_s', 'rfq_issued_at')) {
                $drop[] = 'rfq_issued_at';
            }
            // expected_delivery_date may pre-exist from an earlier migration — only drop if we added it
            // Leave expected_delivery_date in place on rollback to avoid data loss from the older column.
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
