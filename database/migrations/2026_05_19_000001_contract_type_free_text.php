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
     * Convert contract_type from enum/constrained string to free-text varchar.
     * This allows arbitrary contract type values while maintaining the four
     * Emerald standards (emerald, oando, dangote, heritage) as defaults.
     *
     * Any non-standard contract type will route directly to Supply Chain Director.
     */
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            // Drop existing check constraints on contract_type
            DB::statement('ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_contract_type_check');

            // Modify column to varchar without constraint
            // This allows arbitrary strings while preserving existing data
            $table->string('contract_type', 100)->change()->nullable();
        });

        // Add routed_reason column if it doesn't exist
        // This tracks why an MRF was routed to a particular stage
        // Values: 'standard_contract_type', 'custom_contract_type', 'logistics_exception'
        if (!Schema::hasColumn('m_r_f_s', 'routed_reason')) {
            Schema::table('m_r_f_s', function (Blueprint $table) {
                $table->string('routed_reason', 100)->nullable()
                    ->after('contract_type')
                    ->comment('Reason for routing decision: standard_contract_type, custom_contract_type, logistics_exception');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            // Drop routed_reason column
            if (Schema::hasColumn('m_r_f_s', 'routed_reason')) {
                $table->dropColumn('routed_reason');
            }

            // Restore enum constraint (PostgreSQL enum type would need recreation)
            // For now, just add back the check constraint
            DB::statement("
                ALTER TABLE m_r_f_s
                ADD CONSTRAINT m_r_f_s_contract_type_check
                CHECK (contract_type IN ('emerald', 'oando', 'dangote', 'heritage'))
            ");
        });
    }
};
