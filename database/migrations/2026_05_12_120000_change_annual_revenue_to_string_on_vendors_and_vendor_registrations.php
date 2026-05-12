<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store annual revenue as free-form text (e.g. ranges, "₦5M+", descriptive bands).
     */
    public function up(): void
    {
        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'annual_revenue')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('annual_revenue', 255)->nullable()->change();
            });
        }

        if (Schema::hasTable('vendor_registrations') && Schema::hasColumn('vendor_registrations', 'annual_revenue')) {
            Schema::table('vendor_registrations', function (Blueprint $table) {
                $table->string('annual_revenue', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'annual_revenue')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->decimal('annual_revenue', 18, 2)->nullable()->change();
            });
        }

        if (Schema::hasTable('vendor_registrations') && Schema::hasColumn('vendor_registrations', 'annual_revenue')) {
            Schema::table('vendor_registrations', function (Blueprint $table) {
                $table->decimal('annual_revenue', 18, 2)->nullable()->change();
            });
        }
    }
};
