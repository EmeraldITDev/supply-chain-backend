<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (!Schema::hasColumn('m_r_f_s', 'formatted_id')) {
                $table->string('formatted_id', 64)->nullable()->unique()->after('mrf_id');
                $table->index('formatted_id', 'idx_mrfs_formatted_id');
            }
        });

        Schema::table('s_r_f_s', function (Blueprint $table) {
            if (!Schema::hasColumn('s_r_f_s', 'formatted_id')) {
                $table->string('formatted_id', 64)->nullable()->unique()->after('srf_id');
                $table->index('formatted_id', 'idx_srfs_formatted_id');
            }

            // Needed to generate {TYPE}-{CONTRACT}-{DEPT}-{CAT}-{YEAR}-{SEQ} for SRF end-to-end
            if (!Schema::hasColumn('s_r_f_s', 'contract_type')) {
                $table->string('contract_type')->nullable()->after('service_type');
            }
            if (!Schema::hasColumn('s_r_f_s', 'department')) {
                $table->string('department')->nullable()->after('requester_name');
            }
        });

        Schema::table('r_f_q_s', function (Blueprint $table) {
            if (!Schema::hasColumn('r_f_q_s', 'formatted_id')) {
                $table->string('formatted_id', 64)->nullable()->unique()->after('rfq_id');
                $table->index('formatted_id', 'idx_rfqs_formatted_id');
            }

            // RFQ already has category in the model, but the table migration is older.
            if (!Schema::hasColumn('r_f_q_s', 'title')) {
                $table->string('title')->nullable()->after('mrf_title');
            }
            if (!Schema::hasColumn('r_f_q_s', 'category')) {
                $table->string('category')->nullable()->after('title');
            }
            if (!Schema::hasColumn('r_f_q_s', 'workflow_state')) {
                $table->string('workflow_state')->nullable()->after('status');
            }
            if (!Schema::hasColumn('r_f_q_s', 'payment_terms')) {
                $table->text('payment_terms')->nullable()->after('deadline');
            }
            if (!Schema::hasColumn('r_f_q_s', 'notes')) {
                $table->text('notes')->nullable()->after('payment_terms');
            }
            if (!Schema::hasColumn('r_f_q_s', 'supporting_documents')) {
                $table->json('supporting_documents')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('r_f_q_s', 'selected_vendor_id')) {
                $table->foreignId('selected_vendor_id')->nullable()->constrained('vendors')->nullOnDelete()->after('created_by');
            }
            if (!Schema::hasColumn('r_f_q_s', 'selected_quotation_id')) {
                $table->foreignId('selected_quotation_id')->nullable()->constrained('quotations')->nullOnDelete()->after('selected_vendor_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'formatted_id')) {
                $table->dropIndex('idx_mrfs_formatted_id');
                $table->dropColumn('formatted_id');
            }
        });

        Schema::table('s_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('s_r_f_s', 'formatted_id')) {
                $table->dropIndex('idx_srfs_formatted_id');
                $table->dropColumn('formatted_id');
            }
            if (Schema::hasColumn('s_r_f_s', 'contract_type')) {
                $table->dropColumn('contract_type');
            }
            if (Schema::hasColumn('s_r_f_s', 'department')) {
                $table->dropColumn('department');
            }
        });

        Schema::table('r_f_q_s', function (Blueprint $table) {
            if (Schema::hasColumn('r_f_q_s', 'formatted_id')) {
                $table->dropIndex('idx_rfqs_formatted_id');
                $table->dropColumn('formatted_id');
            }
        });
    }
};

