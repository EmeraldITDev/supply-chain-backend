<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds financial information and country metadata for vendor registration form.
     */
    public function up(): void
    {
        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->after('address');
            $table->decimal('account_balance', 18, 2)->nullable()->after('country_code');
            $table->string('bank_name', 255)->nullable()->after('account_balance');
            $table->string('account_number', 64)->nullable()->after('bank_name');
            $table->string('account_name', 255)->nullable()->after('account_number');
            $table->string('currency', 3)->nullable()->after('account_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'country_code',
                'account_balance',
                'bank_name',
                'account_number',
                'account_name',
                'currency',
            ]);
        });
    }
};
