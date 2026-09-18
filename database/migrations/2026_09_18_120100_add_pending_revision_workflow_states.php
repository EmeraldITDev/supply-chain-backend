<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WORKFLOW_STATES = [
        'mrf_created',
        'parallel_first_approval',
        'supply_chain_director_review',
        'supply_chain_director_approved',
        'supply_chain_director_rejected',
        'lazarus_director_approval',
        'procurement_review',
        'procurement_approved',
        'rfq_issued',
        'quotations_received',
        'quotations_evaluated',
        'po_generated',
        'pending_revision',
        'pending_scd_signature',
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

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->replacePostgresCheckConstraint(self::WORKFLOW_STATES);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $legacy = array_values(array_filter(
            self::WORKFLOW_STATES,
            static fn (string $state) => ! in_array($state, ['pending_revision', 'pending_scd_signature'], true)
        ));

        $this->replacePostgresCheckConstraint($legacy);
    }

    /**
     * @param  list<string>  $states
     */
    private function replacePostgresCheckConstraint(array $states): void
    {
        DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check');

        $list = implode(', ', array_map(fn ($s) => "'{$s}'", $states));

        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check
            CHECK (workflow_state IN ({$list}))
        ");
    }
};
