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
            // Only add if it doesn't exist (safe for re-running)
            if (!Schema::hasColumn('m_r_f_s', 'ship_to_address')) {
                if (Schema::hasColumn('m_r_f_s', 'unsigned_po_url')) {
                    $table->text('ship_to_address')->nullable()->after('unsigned_po_url');
                } elseif (Schema::hasColumn('m_r_f_s', 'po_number')) {
                    $table->text('ship_to_address')->nullable()->after('po_number');
                } elseif (Schema::hasColumn('m_r_f_s', 'po_signed_at')) {
                    $table->text('ship_to_address')->nullable()->after('po_signed_at');
                } else {
                    $table->text('ship_to_address')->nullable();
                }
            }
            
            // Tax information - only add if doesn't exist
            if (!Schema::hasColumn('m_r_f_s', 'tax_rate')) {
                if (Schema::hasColumn('m_r_f_s', 'ship_to_address')) {
                    $table->decimal('tax_rate', 5, 2)->default(0)->after('ship_to_address');
                } else {
                    $table->decimal('tax_rate', 5, 2)->default(0);
                }
            }
            
            if (!Schema::hasColumn('m_r_f_s', 'tax_amount')) {
                if (Schema::hasColumn('m_r_f_s', 'tax_rate')) {
                    $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_rate');
                } else {
                    $table->decimal('tax_amount', 15, 2)->default(0);
                }
            }
            
            // Special terms/notes for PO (customizable per MRF)
            if (!Schema::hasColumn('m_r_f_s', 'po_special_terms')) {
                if (Schema::hasColumn('m_r_f_s', 'tax_amount')) {
                    $table->text('po_special_terms')->nullable()->after('tax_amount');
                } else {
                    $table->text('po_special_terms')->nullable();
                }
            }
            
            // Invoice submission email (can override default)
            if (!Schema::hasColumn('m_r_f_s', 'invoice_submission_email')) {
                if (Schema::hasColumn('m_r_f_s', 'po_special_terms')) {
                    $table->string('invoice_submission_email')->nullable()->after('po_special_terms');
                } else {
                    $table->string('invoice_submission_email')->nullable();
                }
            }
            
            if (!Schema::hasColumn('m_r_f_s', 'invoice_submission_cc')) {
                if (Schema::hasColumn('m_r_f_s', 'invoice_submission_email')) {
                    $table->string('invoice_submission_cc')->nullable()->after('invoice_submission_email');
                } else {
                    $table->string('invoice_submission_cc')->nullable();
                }
            }
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
