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
            // Add contract_type column after category
            // Enum values: emerald, oando, dangote, heritage (based on MRFController generateMRFId method)
            if (!Schema::hasColumn('m_r_f_s', 'contract_type')) {
                $table->enum('contract_type', ['emerald', 'oando', 'dangote', 'heritage'])
                    ->nullable()
                    ->after('category')
                    ->comment('Contract type used for MRF ID generation');
            }

            // Add department column if it doesn't exist
            if (!Schema::hasColumn('m_r_f_s', 'department')) {
                $table->string('department', 255)
                    ->nullable()
                    ->after('requester_name')
                    ->comment('Department requesting the MRF');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'contract_type')) {
                $table->dropColumn('contract_type');
            }

            if (Schema::hasColumn('m_r_f_s', 'department')) {
                $table->dropColumn('department');
            }
        });
    }
};
