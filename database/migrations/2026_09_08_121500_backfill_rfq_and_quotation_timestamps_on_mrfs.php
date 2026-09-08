<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill sourcing timestamps so dashboards stop flagging empty RFQ/quotation fields.
 * Uses earliest RFQ / quotation timestamps already stored for each MRF.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        if (Schema::hasColumn('m_r_f_s', 'rfq_issued_at') && Schema::hasTable('r_f_q_s')) {
            DB::statement("
                UPDATE m_r_f_s AS m
                SET rfq_issued_at = sub.first_rfq_at
                FROM (
                    SELECT mrf_id, MIN(created_at) AS first_rfq_at
                    FROM r_f_q_s
                    WHERE mrf_id IS NOT NULL
                    GROUP BY mrf_id
                ) AS sub
                WHERE m.id = sub.mrf_id
                  AND m.rfq_issued_at IS NULL
            ");
        }

        if (Schema::hasColumn('m_r_f_s', 'quotation_received_at')
            && Schema::hasTable('quotations')
            && Schema::hasTable('r_f_q_s')
        ) {
            DB::statement("
                UPDATE m_r_f_s AS m
                SET quotation_received_at = sub.first_quote_at
                FROM (
                    SELECT r.mrf_id,
                           MIN(COALESCE(q.submitted_at, q.created_at)) AS first_quote_at
                    FROM quotations q
                    INNER JOIN r_f_q_s r ON r.id = q.rfq_id
                    WHERE r.mrf_id IS NOT NULL
                    GROUP BY r.mrf_id
                ) AS sub
                WHERE m.id = sub.mrf_id
                  AND m.quotation_received_at IS NULL
            ");
        }
    }

    public function down(): void
    {
        // Irreversible data backfill — intentionally empty.
    }
};
