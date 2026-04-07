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
        // If column exists but is an enum, convert to varchar
        if (Schema::hasColumn('m_r_f_s', 'contract_type')) {
            DB::statement("ALTER TABLE m_r_f_s MODIFY contract_type VARCHAR(255) NULL");
        } else {
            // If column doesn't exist at all, add it
            Schema::table('m_r_f_s', function (Blueprint $table) {
                $table->string('contract_type', 255)->nullable()->after('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
