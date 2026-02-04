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
     * 
     * Adds foreign keys to:
     * - quotations (rfq_id, vendor_id)
     * - vendor_registrations (vendor_id)
     * - rfq_vendors (rfq_id, vendor_id)
     */
    public function up(): void
    {
        // Check if foreign keys already exist before adding them (idempotent migration)
        $quotationsForeignKeys = \DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'quotations' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $existingQuotationsKeys = array_column($quotationsForeignKeys, 'CONSTRAINT_NAME');

        Schema::table('quotations', function (Blueprint $table) use ($existingQuotationsKeys) {
            // Add foreign key constraints after r_f_q_s and vendors tables are created
            if (!in_array('quotations_rfq_id_foreign', $existingQuotationsKeys)) {
                $table->foreign('rfq_id')->references('id')->on('r_f_q_s')->onDelete('cascade');
            }
            if (!in_array('quotations_vendor_id_foreign', $existingQuotationsKeys)) {
                $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
            }
        });

        $vendorRegistrationsForeignKeys = \DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'vendor_registrations' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $existingVendorRegKeys = array_column($vendorRegistrationsForeignKeys, 'CONSTRAINT_NAME');

        Schema::table('vendor_registrations', function (Blueprint $table) use ($existingVendorRegKeys) {
            // Add foreign key constraint after vendors table is created
            if (!in_array('vendor_registrations_vendor_id_foreign', $existingVendorRegKeys)) {
                $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
            }
        });

        $rfqVendorsForeignKeys = \DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'rfq_vendors' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $existingRfqVendorKeys = array_column($rfqVendorsForeignKeys, 'CONSTRAINT_NAME');

        Schema::table('rfq_vendors', function (Blueprint $table) use ($existingRfqVendorKeys) {
            // Add foreign key constraints after r_f_q_s and vendors tables are created
            if (!in_array('rfq_vendors_rfq_id_foreign', $existingRfqVendorKeys)) {
                $table->foreign('rfq_id')->references('id')->on('r_f_q_s')->onDelete('cascade');
            }
            if (!in_array('rfq_vendors_vendor_id_foreign', $existingRfqVendorKeys)) {
                $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
            }
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

