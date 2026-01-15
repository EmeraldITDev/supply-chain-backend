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
     * This migration updates the workflow_state check constraint to include all new workflow states.
     * PostgreSQL doesn't support altering enum types directly, so we need to:
     * 1. Drop the old constraint
     * 2. Change column type to text (if it was enum)
     * 3. Add new check constraint with all allowed values
     */
    public function up(): void
    {
        // For PostgreSQL, we need to drop the old check constraint and create a new one
        // Since the column might be an enum or have a check constraint, we handle both cases
        
        DB::statement("
            ALTER TABLE m_r_f_s
            DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check;
        ");
        
        // If workflow_state is an enum type, we need to convert it to varchar/text first
        // Check if it's an enum type
        $isEnum = DB::select("
            SELECT data_type 
            FROM information_schema.columns 
            WHERE table_name = 'm_r_f_s' 
            AND column_name = 'workflow_state'
            AND data_type = 'USER-DEFINED'
        ");
        
        if (!empty($isEnum)) {
            // Convert enum to text/varchar
            DB::statement("
                ALTER TABLE m_r_f_s 
                ALTER COLUMN workflow_state TYPE text 
                USING workflow_state::text;
            ");
        }
        
        // Now add check constraint with all new workflow states
        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check 
            CHECK (workflow_state IN (
                'mrf_created',
                'executive_review',
                'executive_approved',
                'executive_rejected',
                'procurement_review',
                'vendor_selected',
                'invoice_received',
                'invoice_approved',
                'po_generated',
                'po_signed',
                'payment_processed',
                'grn_requested',
                'grn_completed',
                'closed'
            ));
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the new constraint
        DB::statement("
            ALTER TABLE m_r_f_s
            DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check;
        ");
        
        // Restore old check constraint with old values
        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check 
            CHECK (workflow_state IN (
                'mrf_created',
                'mrf_approved',
                'mrf_rejected',
                'po_generated',
                'po_reviewed',
                'po_signed',
                'po_rejected',
                'payment_processed',
                'grn_requested',
                'grn_completed'
            ));
        ");
    }
};
