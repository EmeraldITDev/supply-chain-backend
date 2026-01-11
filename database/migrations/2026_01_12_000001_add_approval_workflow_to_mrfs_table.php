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
            // Update status enum to include all workflow stages
            $table->dropColumn('status');
        });

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'executive_review',
                'chairman_review',
                'procurement',
                'supply_chain',
                'finance',
                'chairman_payment',
                'completed',
                'rejected'
            ])->default('pending')->after('date');

            // Executive approval
            $table->boolean('executive_approved')->default(false)->after('is_resubmission');
            $table->foreignId('executive_approved_by')->nullable()->constrained('users')->after('executive_approved');
            $table->timestamp('executive_approved_at')->nullable()->after('executive_approved_by');
            $table->text('executive_remarks')->nullable()->after('executive_approved_at');

            // Chairman approval
            $table->boolean('chairman_approved')->default(false)->after('executive_remarks');
            $table->foreignId('chairman_approved_by')->nullable()->constrained('users')->after('chairman_approved');
            $table->timestamp('chairman_approved_at')->nullable()->after('chairman_approved_by');
            $table->text('chairman_remarks')->nullable()->after('chairman_approved_at');

            // PO Information
            $table->string('po_number')->nullable()->after('chairman_remarks');
            $table->text('unsigned_po_url')->nullable()->after('po_number');
            $table->text('signed_po_url')->nullable()->after('unsigned_po_url');
            $table->integer('po_version')->default(1)->after('signed_po_url');
            $table->timestamp('po_generated_at')->nullable()->after('po_version');
            $table->timestamp('po_signed_at')->nullable()->after('po_generated_at');

            // Enhanced rejection tracking
            $table->text('rejection_comments')->nullable()->after('rejection_reason');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->after('rejection_comments');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->foreignId('previous_submission_id')->nullable()->constrained('m_r_f_s')->after('rejected_at');

            // Finance & Payment
            $table->enum('payment_status', ['pending', 'processing', 'approved', 'paid', 'rejected'])->nullable()->after('previous_submission_id');
            $table->timestamp('payment_approved_at')->nullable()->after('payment_status');
            $table->foreignId('payment_approved_by')->nullable()->constrained('users')->after('payment_approved_at');

            // Add currency field
            $table->string('currency', 3)->default('NGN')->after('estimated_cost');

            // Add indexes for performance
            $table->index('status');
            $table->index('current_stage');
            $table->index('executive_approved');
            $table->index('chairman_approved');
            $table->index('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['status']);
            $table->dropIndex(['current_stage']);
            $table->dropIndex(['executive_approved']);
            $table->dropIndex(['chairman_approved']);
            $table->dropIndex(['payment_status']);

            // Drop all added columns
            $table->dropForeign(['executive_approved_by']);
            $table->dropForeign(['chairman_approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['previous_submission_id']);
            $table->dropForeign(['payment_approved_by']);

            $table->dropColumn([
                'executive_approved',
                'executive_approved_by',
                'executive_approved_at',
                'executive_remarks',
                'chairman_approved',
                'chairman_approved_by',
                'chairman_approved_at',
                'chairman_remarks',
                'po_number',
                'unsigned_po_url',
                'signed_po_url',
                'po_version',
                'po_generated_at',
                'po_signed_at',
                'rejection_comments',
                'rejected_by',
                'rejected_at',
                'previous_submission_id',
                'payment_status',
                'payment_approved_at',
                'payment_approved_by',
                'currency',
            ]);
        });

        // Restore old status enum
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'In Progress', 'Completed'])->default('Pending');
        });
    }
};
