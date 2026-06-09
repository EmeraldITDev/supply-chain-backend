<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            if (! Schema::hasColumn('r_f_q_s', 'delivery_terms')) {
                $table->text('delivery_terms')->nullable()->after('payment_terms');
            }

            if (! Schema::hasColumn('r_f_q_s', 'technical_requirements')) {
                $table->text('technical_requirements')->nullable()->after('delivery_terms');
            }

            if (! Schema::hasColumn('r_f_q_s', 'additional_notes')) {
                $table->text('additional_notes')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('r_f_q_s', 'terms_and_conditions')) {
                $table->text('terms_and_conditions')->nullable()->after('additional_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            foreach (['terms_and_conditions', 'additional_notes', 'technical_requirements', 'delivery_terms'] as $column) {
                if (Schema::hasColumn('r_f_q_s', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
