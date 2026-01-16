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
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->string('contract_type', 50)->nullable()->after('category')
                ->comment('Contract type: emerald, oando, dangote, heritage');
        });
        
        // Add check constraint for contract_type
        \DB::statement("
            ALTER TABLE m_r_f_s
            ADD CONSTRAINT m_r_f_s_contract_type_check 
            CHECK (contract_type IS NULL OR contract_type IN ('emerald', 'oando', 'dangote', 'heritage'));
        ");
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
