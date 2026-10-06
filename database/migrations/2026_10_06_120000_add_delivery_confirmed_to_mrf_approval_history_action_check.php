<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow delivery_confirmed on mrf_approval_history action CHECK.
     */
    public function up(): void
    {
        if (! Schema::hasTable('mrf_approval_history')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE mrf_approval_history DROP CONSTRAINT IF EXISTS mrf_approval_history_action_check');

        DB::statement("ALTER TABLE mrf_approval_history ADD CONSTRAINT mrf_approval_history_action_check
            CHECK (action IN (
                'approved',
                'rejected',
                'returned',
                'generated_po',
                'signed_po',
                'rejected_po',
                'payment_processed',
                'payment_approved',
                'vendor_selected',
                'vendor_approved',
                'vendor_rejected',
                'po_deleted',
                'force_closed',
                'requester_edit',
                'delivery_confirmed'
            ))");
    }

    public function down(): void
    {
        if (! Schema::hasTable('mrf_approval_history')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE mrf_approval_history DROP CONSTRAINT IF EXISTS mrf_approval_history_action_check');

        DB::statement("ALTER TABLE mrf_approval_history ADD CONSTRAINT mrf_approval_history_action_check
            CHECK (action IN (
                'approved',
                'rejected',
                'returned',
                'generated_po',
                'signed_po',
                'rejected_po',
                'payment_processed',
                'payment_approved',
                'vendor_selected',
                'vendor_approved',
                'vendor_rejected',
                'po_deleted',
                'force_closed',
                'requester_edit'
            ))");
    }
};
