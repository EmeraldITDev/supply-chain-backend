<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            // Add title if it doesn't exist (some tables might have mrf_title instead)
            if (!Schema::hasColumn('r_f_q_s', 'title')) {
                $table->string('title')->nullable()->after('mrf_title');
            }
            
            // Add payment_terms if it doesn't exist
            if (!Schema::hasColumn('r_f_q_s', 'payment_terms')) {
                $table->text('payment_terms')->nullable()->after('deadline');
            }
            
            // Add notes if it doesn't exist
            if (!Schema::hasColumn('r_f_q_s', 'notes')) {
                $table->text('notes')->nullable()->after('payment_terms');
            }
            
            // Ensure estimated_cost exists (should already exist)
            if (!Schema::hasColumn('r_f_q_s', 'estimated_cost')) {
                $table->decimal('estimated_cost', 15, 2)->nullable()->after('quantity');
            }
            
            // Add workflow_state for RFQ tracking
            if (!Schema::hasColumn('r_f_q_s', 'workflow_state')) {
                $table->string('workflow_state', 50)->default('draft')->after('status');
            }
        });
        
        // Add check constraint for workflow_state
        DB::statement("
            ALTER TABLE r_f_q_s
            DROP CONSTRAINT IF EXISTS r_f_q_s_workflow_state_check;
        ");
        
        DB::statement("
            ALTER TABLE r_f_q_s
            ADD CONSTRAINT r_f_q_s_workflow_state_check 
            CHECK (workflow_state IN (
                'draft',
                'open',
                'quotation_received',
                'procurement_review',
                'supply_chain_review',
                'approved',
                'rejected',
                'closed'
            ));
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            DB::statement("ALTER TABLE r_f_q_s DROP CONSTRAINT IF EXISTS r_f_q_s_workflow_state_check;");
            
            if (Schema::hasColumn('r_f_q_s', 'workflow_state')) {
                $table->dropColumn('workflow_state');
            }
            if (Schema::hasColumn('r_f_q_s', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('r_f_q_s', 'payment_terms')) {
                $table->dropColumn('payment_terms');
            }
            if (Schema::hasColumn('r_f_q_s', 'title')) {
                $table->dropColumn('title');
            }
        });
    }
};
