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
        Schema::table('r_f_q_s', function (Blueprint $table) {
            // Add vendor selection fields
            $table->foreignId('selected_vendor_id')->nullable()->constrained('vendors')->after('status');
            $table->foreignId('selected_quotation_id')->nullable()->constrained('quotations')->after('selected_vendor_id');

            $table->index('selected_vendor_id');
            $table->index('selected_quotation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            $table->dropForeign(['selected_vendor_id']);
            $table->dropForeign(['selected_quotation_id']);
            $table->dropIndex(['selected_vendor_id']);
            $table->dropIndex(['selected_quotation_id']);
            $table->dropColumn(['selected_vendor_id', 'selected_quotation_id']);
        });
    }
};
