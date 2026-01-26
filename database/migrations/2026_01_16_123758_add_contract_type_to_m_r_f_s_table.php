<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only add column if it doesn't exist (safe for re-running)
        if (!Schema::hasColumn('m_r_f_s', 'contract_type')) {
            Schema::table('m_r_f_s', function (Blueprint $table) {
                if (Schema::hasColumn('m_r_f_s', 'category')) {
                    $table->string('contract_type', 50)->nullable()->after('category')
                        ->comment('Contract type: emerald, oando, dangote, heritage');
                } else {
                    $table->string('contract_type', 50)->nullable()
                        ->comment('Contract type: emerald, oando, dangote, heritage');
                }
            });
        }
        
        // Add check constraint for contract_type (drop first if exists)
        try {
            \DB::statement("ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_contract_type_check;");
        } catch (\Exception $e) {
            // Ignore if constraint doesn't exist
        }
        
        try {
            \DB::statement("
                ALTER TABLE m_r_f_s
                ADD CONSTRAINT m_r_f_s_contract_type_check 
                CHECK (contract_type IS NULL OR contract_type IN ('emerald', 'oando', 'dangote', 'heritage'));
            ");
        } catch (\Exception $e) {
            // Constraint might already exist, ignore
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            \DB::statement("ALTER TABLE m_r_f_s DROP CONSTRAINT IF EXISTS m_r_f_s_contract_type_check;");
            $table->dropColumn('contract_type');
        });
    }
};
