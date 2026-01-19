<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Laravel's enum() creates a CHECK constraint in PostgreSQL.
     * We need to drop the old constraint and create a new one with additional values.
     */
    public function up(): void
    {
        // Drop the existing check constraint (name from error: mrf_approval_history_action_check)
        DB::statement("ALTER TABLE mrf_approval_history DROP CONSTRAINT IF EXISTS mrf_approval_history_action_check");
        
        // Add new check constraint with all allowed values including vendor workflow actions
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
                'po_deleted'
            ))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the new constraint
        DB::statement("ALTER TABLE mrf_approval_history DROP CONSTRAINT IF EXISTS mrf_approval_history_action_check");
        
        // Restore the original constraint
        DB::statement("ALTER TABLE mrf_approval_history ADD CONSTRAINT mrf_approval_history_action_check 
            CHECK (action IN (
                'approved', 
                'rejected', 
                'returned', 
                'generated_po', 
                'signed_po', 
                'rejected_po', 
                'payment_processed', 
                'payment_approved'
            ))");
    }
};
