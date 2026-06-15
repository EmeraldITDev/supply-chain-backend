<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_completion_certificates', function (Blueprint $table) {
            if (! Schema::hasColumn('job_completion_certificates', 'currency')) {
                $table->string('currency', 3)->default('NGN')->after('certification_text');
            }
            if (! Schema::hasColumn('job_completion_certificates', 'subtotal')) {
                $table->decimal('subtotal', 15, 2)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('job_completion_certificates', 'vat')) {
                $table->decimal('vat', 15, 2)->nullable()->after('subtotal');
            }
            if (! Schema::hasColumn('job_completion_certificates', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->nullable()->after('vat');
            }
            if (! Schema::hasColumn('job_completion_certificates', 'date_issued')) {
                $table->date('date_issued')->nullable()->after('issued_at');
            }
        });

        Schema::table('job_completion_certificate_line_items', function (Blueprint $table) {
            if (! Schema::hasColumn('job_completion_certificate_line_items', 'unit')) {
                $table->string('unit', 50)->nullable()->after('description');
            }
            if (! Schema::hasColumn('job_completion_certificate_line_items', 'quantity')) {
                $table->decimal('quantity', 12, 2)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('job_completion_certificate_line_items', 'unit_price')) {
                $table->decimal('unit_price', 15, 2)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('job_completion_certificate_line_items', 'amount')) {
                $table->decimal('amount', 15, 2)->nullable()->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_completion_certificate_line_items', function (Blueprint $table) {
            $table->dropColumn(['unit', 'quantity', 'unit_price', 'amount']);
        });

        Schema::table('job_completion_certificates', function (Blueprint $table) {
            $table->dropColumn(['currency', 'subtotal', 'vat', 'total_amount', 'date_issued']);
        });
    }
};
