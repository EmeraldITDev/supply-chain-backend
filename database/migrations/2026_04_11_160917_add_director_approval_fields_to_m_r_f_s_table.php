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
            if (!Schema::hasColumn('m_r_f_s', 'director_approved_by')) {
                $table->string('director_approved_by')->nullable();
            }
    
            if (!Schema::hasColumn('m_r_f_s', 'director_remarks')) {
                $table->text('director_remarks')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'director_approved_by')) {
                $table->dropColumn('director_approved_by');
            }
    
            if (Schema::hasColumn('m_r_f_s', 'director_remarks')) {
                $table->dropColumn('director_remarks');
            }
        });
    }
};
