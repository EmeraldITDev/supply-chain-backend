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
            // Ship to address (can be different from company address)
            // Place after unsigned_po_url if it exists, otherwise just add
            if (Schema::hasColumn('m_r_f_s', 'unsigned_po_url')) {
                $table->text('ship_to_address')->nullable()->after('unsigned_po_url');
            } elseif (Schema::hasColumn('m_r_f_s', 'po_number')) {
                $table->text('ship_to_address')->nullable()->after('po_number');
            } else {
                $table->text('ship_to_address')->nullable();
            }
            
            // Tax information
            $table->decimal('tax_rate', 5, 2)->default(0)->after('ship_to_address'); // Tax rate as percentage (e.g., 7.50 for 7.5%)
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_rate'); // Calculated tax amount
            
            // Special terms/notes for PO (customizable per MRF)
            $table->text('po_special_terms')->nullable()->after('tax_amount');
            
            // Invoice submission email (can override default)
            $table->string('invoice_submission_email')->nullable()->after('po_special_terms');
            $table->string('invoice_submission_cc')->nullable()->after('invoice_submission_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropColumn([
                'ship_to_address',
                'tax_rate',
                'tax_amount',
                'po_special_terms',
                'invoice_submission_email',
                'invoice_submission_cc',
            ]);
        });
    }
};
