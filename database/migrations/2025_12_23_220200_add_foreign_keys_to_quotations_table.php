<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds foreign key constraints that were removed from initial table creation
     * to avoid dependency issues when tables are created in alphabetical order.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Add foreign key constraints after r_f_q_s and vendors tables are created
            $table->foreign('rfq_id')->references('id')->on('r_f_q_s')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });

        Schema::table('vendor_registrations', function (Blueprint $table) {
            // Add foreign key constraint after vendors table is created
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
        });

        Schema::table('rfq_vendors', function (Blueprint $table) {
            // Add foreign key constraints after r_f_q_s and vendors tables are created
            $table->foreign('rfq_id')->references('id')->on('r_f_q_s')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['rfq_id']);
            $table->dropForeign(['vendor_id']);
        });

        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
        });

        Schema::table('rfq_vendors', function (Blueprint $table) {
            $table->dropForeign(['rfq_id']);
            $table->dropForeign(['vendor_id']);
        });
    }
};

