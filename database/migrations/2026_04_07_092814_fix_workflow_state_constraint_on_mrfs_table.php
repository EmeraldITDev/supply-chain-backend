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
    }
};
