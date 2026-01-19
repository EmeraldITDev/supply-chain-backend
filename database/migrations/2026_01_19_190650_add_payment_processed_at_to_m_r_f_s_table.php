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
            // Add payment_processed_at column after payment_approved_at
            // This tracks when finance processes the payment (moves to chairman_payment stage)
            $table->timestamp('payment_processed_at')->nullable()->after('payment_approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropColumn('payment_processed_at');
        });
    }
};
