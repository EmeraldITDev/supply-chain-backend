<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE m_r_f_s
            DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check
        ");

        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check
            CHECK (
                workflow_state IN (
                    'mrf_created',
                    'supply_chain_director_review',
                    'supply_chain_director_approved',
                    'supply_chain_director_rejected',
                    'procurement_review',
                    'procurement_approved',
                    'rfq_issued',
                    'quotations_received',
                    'quotations_evaluated',
                    'po_generated',
                    'po_signed',
                    'closed',
                    'executive_review',
                    'executive_approved',
                    'executive_rejected',
                    'vendor_selected',
                    'invoice_received',
                    'invoice_approved',
                    'payment_processed',
                    'grn_requested',
                    'grn_completed'
                )
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE m_r_f_s
            DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check
        ");

        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check
            CHECK (
                workflow_state IN (
                    'executive_review',
                    'supply_chain_director_review'
                )
            )
        ");
    }
};
