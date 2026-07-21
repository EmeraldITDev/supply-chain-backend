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
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'status')) {
                $table->string('status')->default('pending');
            }

            if (! Schema::hasColumn('m_r_f_s', 'executive_approved')) {
                $table->boolean('executive_approved')->default(false);
            }
            if (! Schema::hasColumn('m_r_f_s', 'executive_approved_by')) {
                $table->foreignId('executive_approved_by')->nullable()->constrained('users');
            }
            if (! Schema::hasColumn('m_r_f_s', 'executive_approved_at')) {
                $table->timestamp('executive_approved_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'executive_remarks')) {
                $table->text('executive_remarks')->nullable();
            }

            if (! Schema::hasColumn('m_r_f_s', 'chairman_approved')) {
                $table->boolean('chairman_approved')->default(false);
            }
            if (! Schema::hasColumn('m_r_f_s', 'chairman_approved_by')) {
                $table->foreignId('chairman_approved_by')->nullable()->constrained('users');
            }
            if (! Schema::hasColumn('m_r_f_s', 'chairman_approved_at')) {
                $table->timestamp('chairman_approved_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'chairman_remarks')) {
                $table->text('chairman_remarks')->nullable();
            }

            if (! Schema::hasColumn('m_r_f_s', 'po_number')) {
                $table->string('po_number')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'unsigned_po_url')) {
                $table->text('unsigned_po_url')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'signed_po_url')) {
                $table->text('signed_po_url')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'po_version')) {
                $table->integer('po_version')->default(1);
            }
            if (! Schema::hasColumn('m_r_f_s', 'po_generated_at')) {
                $table->timestamp('po_generated_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'po_signed_at')) {
                $table->timestamp('po_signed_at')->nullable();
            }

            if (! Schema::hasColumn('m_r_f_s', 'rejection_comments')) {
                $table->text('rejection_comments')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users');
            }
            if (! Schema::hasColumn('m_r_f_s', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'previous_submission_id')) {
                $table->foreignId('previous_submission_id')->nullable()->constrained('m_r_f_s');
            }

            if (! Schema::hasColumn('m_r_f_s', 'payment_status')) {
                $table->string('payment_status')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'payment_approved_at')) {
                $table->timestamp('payment_approved_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'payment_approved_by')) {
                $table->foreignId('payment_approved_by')->nullable()->constrained('users');
            }

            if (! Schema::hasColumn('m_r_f_s', 'currency')) {
                $table->string('currency', 3)->default('NGN');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropForeign(['executive_approved_by']);
            $table->dropForeign(['chairman_approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['previous_submission_id']);
            $table->dropForeign(['payment_approved_by']);
        });
    }
};
