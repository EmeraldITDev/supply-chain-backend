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
            if (!Schema::hasColumn('m_r_f_s', 'executive_approved_at')) {
                $table->timestamp('executive_approved_at')->nullable();
            }
    
            if (!Schema::hasColumn('m_r_f_s', 'director_approved_at')) {
                $table->timestamp('director_approved_at')->nullable();
            }
    
            if (!Schema::hasColumn('m_r_f_s', 'procurement_review_started_at')) {
                $table->timestamp('procurement_review_started_at')->nullable();
            }    
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'executive_approved_at')) {
                $table->dropColumn('executive_approved_at');
            }
    
            if (Schema::hasColumn('m_r_f_s', 'director_approved_at')) {
                $table->dropColumn('director_approved_at');
            }
    
            if (Schema::hasColumn('m_r_f_s', 'procurement_review_started_at')) {
                $table->dropColumn('procurement_review_started_at');
            }    
        });
    }
};
