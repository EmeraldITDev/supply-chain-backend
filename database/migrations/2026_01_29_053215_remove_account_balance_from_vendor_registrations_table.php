<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Removes account_balance column from vendor_registrations table.
     */
    public function up(): void
    {
        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->dropColumn('account_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->decimal('account_balance', 18, 2)->nullable()->after('country_code');
        });
    }
};
