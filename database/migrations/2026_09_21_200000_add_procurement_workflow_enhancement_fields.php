<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            if (! Schema::hasColumn('r_f_q_s', 'selection_reason')) {
                $table->text('selection_reason')->nullable()->after('selected_quotation_id');
            }
            if (! Schema::hasColumn('r_f_q_s', 'selected_at')) {
                $table->timestamp('selected_at')->nullable()->after('selection_reason');
            }
            if (! Schema::hasColumn('r_f_q_s', 'selected_by')) {
                $table->foreignId('selected_by')->nullable()->after('selected_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('r_f_q_s', 'custom_payment_schedule')) {
                $table->json('custom_payment_schedule')->nullable()->after('payment_terms');
            }
        });

        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'vendor_fulfilment_recorded_at')) {
                $table->timestamp('vendor_fulfilment_recorded_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'force_closed_at')) {
                $table->timestamp('force_closed_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'force_closed_by')) {
                $table->foreignId('force_closed_by')->nullable()
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('m_r_f_s', 'force_close_reason')) {
                $table->text('force_close_reason')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'force_close_previous_status')) {
                $table->string('force_close_previous_status')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'force_close_previous_workflow_state')) {
                $table->string('force_close_previous_workflow_state')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            $drop = [];
            foreach (['custom_payment_schedule', 'selected_by', 'selected_at', 'selection_reason'] as $col) {
                if (Schema::hasColumn('r_f_q_s', $col)) {
                    $drop[] = $col;
                }
            }
            if (in_array('selected_by', $drop, true)) {
                $table->dropForeign(['selected_by']);
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $drop = [];
            foreach ([
                'vendor_fulfilment_recorded_at',
                'force_closed_at',
                'force_closed_by',
                'force_close_reason',
                'force_close_previous_status',
                'force_close_previous_workflow_state',
            ] as $col) {
                if (Schema::hasColumn('m_r_f_s', $col)) {
                    $drop[] = $col;
                }
            }
            if (in_array('force_closed_by', $drop, true)) {
                $table->dropForeign(['force_closed_by']);
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
