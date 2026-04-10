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
            $table->timestamp('executive_approved_at')->nullable();
            $table->timestamp('director_approved_at')->nullable();
            $table->timestamp('procurement_review_started_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropColumn([
                'executive_approved_at',
                'director_approved_at',
                'procurement_review_started_at'
            ]);
        });
    }
};
