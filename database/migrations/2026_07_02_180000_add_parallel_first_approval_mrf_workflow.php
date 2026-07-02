<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        if (Schema::hasTable('m_r_f_s') && ! Schema::hasColumn('m_r_f_s', 'first_approval_by_role')) {
            Schema::table('m_r_f_s', function (Blueprint $table) {
                $table->string('first_approval_by_role', 50)->nullable()->after('workflow_state');
            });
        }

        if (! Schema::hasColumn('m_r_f_s', 'workflow_state')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->replacePostgresCheckConstraint();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_r_f_s') && Schema::hasColumn('m_r_f_s', 'first_approval_by_role')) {
            Schema::table('m_r_f_s', function (Blueprint $table) {
                $table->dropColumn('first_approval_by_role');
            });
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'pgsql') {
            return;
        }

        $legacyStates = array_values(array_filter(
            self::WORKFLOW_STATES,
            static fn (string $state) => $state !== 'parallel_first_approval'
        ));

        DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check');

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
