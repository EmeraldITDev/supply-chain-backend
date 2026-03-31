<?php

namespace Database\Migrations;

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
        Schema::table('vendor_registration_documents', function (Blueprint $table) {
            // Add expiry_date column if it doesn't exist
            if (!Schema::hasColumn('vendor_registration_documents', 'expiry_date')) {
                $table->dateTime('expiry_date')->nullable()->after('uploaded_at');
                $table->comment('Date when document expires and needs renewal');
            }

            // Add is_required flag if it doesn't exist
            if (!Schema::hasColumn('vendor_registration_documents', 'is_required')) {
                $table->boolean('is_required')->default(false)->after('expiry_date');
                $table->comment('Whether this document is required for vendor approval');
            }

            // Add status column if it doesn't exist (track document approval/expiry status)
            if (!Schema::hasColumn('vendor_registration_documents', 'status')) {
                $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Expired'])->default('Pending')->after('is_required');
                $table->comment('Document status: Pending, Approved, Rejected, or Expired');
            }
        });

        // Add indexes for performance
        Schema::table('vendor_registration_documents', function (Blueprint $table) {
            if (!Schema::hasIndex('vendor_registration_documents', 'idx_expiry_date')) {
                $table->index('expiry_date', 'idx_expiry_date');
            }

            if (!Schema::hasIndex('vendor_registration_documents', 'idx_status')) {
                $table->index('status', 'idx_status');
            }

            if (!Schema::hasIndex('vendor_registration_documents', 'idx_registration_status')) {
                $table->index(['vendor_registration_id', 'status'], 'idx_registration_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_registration_documents', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndexIfExists('idx_expiry_date');
            $table->dropIndexIfExists('idx_status');
            $table->dropIndexIfExists('idx_registration_status');

            // Drop columns
            if (Schema::hasColumn('vendor_registration_documents', 'expiry_date')) {
                $table->dropColumn('expiry_date');
            }

            if (Schema::hasColumn('vendor_registration_documents', 'is_required')) {
                $table->dropColumn('is_required');
            }

            if (Schema::hasColumn('vendor_registration_documents', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
