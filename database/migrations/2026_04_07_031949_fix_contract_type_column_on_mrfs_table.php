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
    if (Schema::hasColumn('m_r_f_s', 'contract_type')) {

        // Convert column to VARCHAR if it was previously an enum
        DB::statement("
            ALTER TABLE m_r_f_s
            ALTER COLUMN contract_type TYPE VARCHAR(255)
        ");

        // Ensure it is nullable
        DB::statement("
            ALTER TABLE m_r_f_s
            ALTER COLUMN contract_type DROP NOT NULL
        ");

    } else {

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->string('contract_type', 255)->nullable();
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
