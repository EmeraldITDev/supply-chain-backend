<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WORKFLOW_STATES = [
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
        'delivery_confirmation_pending',
        'delivery_confirmation_complete',
        'finance_handoff_pending',
        'finance_in_review',
        'milestone_payment_in_progress',
        'financially_complete',
        'operationally_complete',
        'closed',
        'executive_review',
        'executive_approved',
        'executive_rejected',
        'vendor_selected',
        'invoice_received',
        'invoice_approved',
        'payment_processed',
        'grn_requested',
        'grn_completed',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('m_r_f_s', 'workflow_state')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->replacePostgresCheckConstraint();
        }

        // Align signed PO records missing canonical workflow_state.
        DB::table('m_r_f_s')
            ->whereNotNull('signed_po_url')
            ->where(function ($query) {
                $query->whereNull('workflow_state')
                    ->orWhereNotIn('workflow_state', ['po_signed', 'delivery_confirmation_pending', 'delivery_confirmation_complete', 'finance_handoff_pending', 'finance_in_review', 'milestone_payment_in_progress', 'financially_complete', 'operationally_complete', 'closed', 'payment_processed', 'grn_requested', 'grn_completed']);
            })
            ->update(['workflow_state' => 'po_signed']);
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check');

        $legacyStates = [
            'mrf_created', 'supply_chain_director_review', 'supply_chain_director_approved',
            'supply_chain_director_rejected', 'procurement_review', 'procurement_approved',
            'rfq_issued', 'quotations_received', 'quotations_evaluated', 'po_generated',
            'po_signed', 'closed', 'executive_review', 'executive_approved', 'executive_rejected',
            'vendor_selected', 'invoice_received', 'invoice_approved', 'payment_processed',
            'grn_requested', 'grn_completed',
        ];

        $list = implode(', ', array_map(fn ($s) => "'{$s}'", $legacyStates));

        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check
            CHECK (workflow_state IN ({$list}))
        ");
    }

    private function replacePostgresCheckConstraint(): void
    {
        DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check');

        $list = implode(', ', array_map(fn ($s) => "'{$s}'", self::WORKFLOW_STATES));

        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check
            CHECK (workflow_state IN ({$list}))
        ");
    }
};
