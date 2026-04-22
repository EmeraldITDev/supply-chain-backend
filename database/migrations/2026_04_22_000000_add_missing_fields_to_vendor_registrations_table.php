<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds missing profile fields to vendor_registrations table
     */
    public function up(): void
    {
        Schema::table('vendor_registrations', function (Blueprint $table) {
            // Add missing fields if they don't exist
            if (!Schema::hasColumn('vendor_registrations', 'annual_revenue')) {
                $table->decimal('annual_revenue', 18, 2)->nullable()->after('currency');
            }
            if (!Schema::hasColumn('vendor_registrations', 'number_of_employees')) {
                $table->string('number_of_employees')->nullable()->after('annual_revenue');
            }
            if (!Schema::hasColumn('vendor_registrations', 'year_established')) {
                $table->integer('year_established')->nullable()->after('number_of_employees');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'annual_revenue',
                'number_of_employees',
                'year_established',
            ]);
        });
    }
};
