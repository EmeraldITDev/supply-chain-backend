<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds additional profile fields to the vendors table that are collected during registration.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Website
            $table->string('website')->nullable()->after('tax_id');

            // Business information
            $table->integer('year_established')->nullable()->after('website');
            $table->string('number_of_employees')->nullable()->after('year_established');
            $table->decimal('annual_revenue', 18, 2)->nullable()->after('number_of_employees');

            // Contact person details
            $table->string('contact_person_title')->nullable()->after('contact_person');
            $table->string('contact_person_email')->nullable()->after('contact_person_title');
            $table->string('contact_person_phone')->nullable()->after('contact_person_email');

            // Address details (already has address, adding more granular fields)
            $table->string('city')->nullable()->after('address');
            $table->string('state')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('state');
            $table->string('country_code', 2)->nullable()->after('postal_code');
            $table->string('alternate_phone')->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'website',
                'year_established',
                'number_of_employees',
                'annual_revenue',
                'contact_person_title',
                'contact_person_email',
                'contact_person_phone',
                'city',
                'state',
                'postal_code',
                'country_code',
                'alternate_phone',
            ]);
        });
    }
};
