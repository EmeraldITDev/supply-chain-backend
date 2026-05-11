<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_completion_certificates', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_id')->nullable()->after('trip_id')->index();
            $table->string('po_number', 100)->nullable()->after('reference_number');
            $table->text('certification_text')->nullable()->after('po_number');
            $table->date('service_period_start')->nullable()->after('certification_text');
            $table->date('service_period_end')->nullable()->after('service_period_start');

            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_completion_certificates', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn([
                'vendor_id',
                'po_number',
                'certification_text',
                'service_period_start',
                'service_period_end',
            ]);
        });
    }
};
