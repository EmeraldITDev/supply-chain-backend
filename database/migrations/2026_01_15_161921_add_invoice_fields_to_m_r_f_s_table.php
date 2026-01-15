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
            // Vendor selection and invoice fields
            $table->foreignId('selected_vendor_id')->nullable()->after('executive_approved_at')->constrained('vendors')->onDelete('set null');
            $table->text('invoice_url')->nullable()->after('selected_vendor_id');
            $table->text('invoice_share_url')->nullable()->after('invoice_url');
            $table->foreignId('invoice_approved_by')->nullable()->after('invoice_share_url')->constrained('users')->onDelete('set null');
            $table->timestamp('invoice_approved_at')->nullable()->after('invoice_approved_by');
            $table->text('invoice_remarks')->nullable()->after('invoice_approved_at');
            
            // Expected delivery date from PO
            $table->date('expected_delivery_date')->nullable()->after('po_signed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropForeign(['selected_vendor_id']);
            $table->dropForeign(['invoice_approved_by']);
            $table->dropColumn([
                'selected_vendor_id',
                'invoice_url',
                'invoice_share_url',
                'invoice_approved_by',
                'invoice_approved_at',
                'invoice_remarks',
                'expected_delivery_date',
            ]);
        });
    }
};
