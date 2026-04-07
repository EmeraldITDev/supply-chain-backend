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
        DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check');

        DB::statement("
            UPDATE m_r_f_s
            SET workflow_state = 'supply_chain_director_review'
            WHERE workflow_state = 'procurement_review'
        ");

        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check
            CHECK (workflow_state IN (
                'executive_review',
                'supply_chain_director_review'
            ))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_workflow_state_check');

        DB::statement("
            UPDATE m_r_f_s
            SET workflow_state = 'procurement_review'
            WHERE workflow_state = 'supply_chain_director_review'
        ");

        DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_workflow_state_check
            CHECK (workflow_state IN (
                'executive_review',
                'procurement_review'
            ))
        ");
    }
};
