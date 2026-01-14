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
            // Add workflow state
            $table->enum('workflow_state', [
                'mrf_created',
                'mrf_approved',
                'mrf_rejected',
                'po_generated',
                'po_reviewed',
                'po_signed',
                'po_rejected',
                'payment_processed',
                'grn_requested',
                'grn_completed'
            ])->default('mrf_created')->after('current_stage');

            // Add PFI (Proforma Invoice) fields
            $table->text('pfi_url')->nullable()->after('justification');
            $table->text('pfi_share_url')->nullable()->after('pfi_url');

            // Add GRN (Goods Received Note) fields
            $table->boolean('grn_requested')->default(false)->after('payment_status');
            $table->timestamp('grn_requested_at')->nullable()->after('grn_requested');
            $table->foreignId('grn_requested_by')->nullable()->constrained('users')->after('grn_requested_at');
            $table->boolean('grn_completed')->default(false)->after('grn_requested_by');
            $table->timestamp('grn_completed_at')->nullable()->after('grn_completed');
            $table->foreignId('grn_completed_by')->nullable()->constrained('users')->after('grn_completed_at');
            $table->text('grn_url')->nullable()->after('grn_completed_by');
            $table->text('grn_share_url')->nullable()->after('grn_url');

            // Add index for workflow state
            $table->index('workflow_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropIndex(['workflow_state']);
            $table->dropForeign(['grn_requested_by']);
            $table->dropForeign(['grn_completed_by']);
            $table->dropColumn([
                'workflow_state',
                'pfi_url',
                'pfi_share_url',
                'grn_requested',
                'grn_requested_at',
                'grn_requested_by',
                'grn_completed',
                'grn_completed_at',
                'grn_completed_by',
                'grn_url',
                'grn_share_url',
            ]);
        });
    }
};
