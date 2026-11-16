<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL/MariaDB only: make three MRF columns match production (PostgreSQL).
 *
 * On Postgres they are varchar columns (Laravel "enum" = varchar + CHECK) and later migrations
 * widened their allowed values on Postgres only. On MySQL they stayed native ENUMs with their
 * first value lists, which reject what production uses today:
 *   - m_r_f_s.status            ENUM('Pending',...) vs live values like 'pending', 'procurement_review'
 *   - m_r_f_s.workflow_state    ENUM + CHECK missing 11 of the 32 current workflow stages
 *   - mrf_approval_history.action ENUM missing 7 values (its CHECK is already complete)
 *
 * The workflow_state CHECK is set to production's current list (2026-11-16).
 * Nothing runs on Postgres.
 */
return new class extends Migration
{
    private const WORKFLOW_STATES = [
        'mrf_created', 'parallel_first_approval', 'supply_chain_director_review',
        'supply_chain_director_approved', 'supply_chain_director_rejected', 'lazarus_director_approval',
        'procurement_review', 'procurement_approved', 'rfq_issued', 'quotations_received',
        'quotations_evaluated', 'po_generated', 'pending_revision', 'pending_scd_signature', 'po_signed',
        'delivery_confirmation_pending', 'delivery_confirmation_complete', 'finance_handoff_pending',
        'finance_in_review', 'milestone_payment_in_progress', 'financially_complete',
        'operationally_complete', 'closed', 'executive_review', 'executive_approved', 'executive_rejected',
        'vendor_selected', 'invoice_received', 'invoice_approved', 'payment_processed', 'grn_requested',
        'grn_completed',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (Schema::hasColumn('m_r_f_s', 'status')) {
            DB::statement("ALTER TABLE m_r_f_s MODIFY status VARCHAR(255) NOT NULL DEFAULT 'pending'");
        }

        if (Schema::hasColumn('m_r_f_s', 'workflow_state')) {
            DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check');
            DB::statement("ALTER TABLE m_r_f_s MODIFY workflow_state VARCHAR(255) NOT NULL DEFAULT 'mrf_created'");
            $list = implode(', ', array_map(fn ($s) => "'{$s}'", self::WORKFLOW_STATES));
            DB::statement("ALTER TABLE m_r_f_s ADD CONSTRAINT m_r_f_s_workflow_state_check CHECK (workflow_state IN ({$list}))");
        }

        if (Schema::hasColumn('mrf_approval_history', 'action')) {
            DB::statement('ALTER TABLE mrf_approval_history MODIFY action VARCHAR(255) NOT NULL');
        }
    }

    public function down(): void
    {
        // Intentionally empty: the old ENUM lists reject production data.
    }
};
