<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'bank_name')) {
                $table->string('bank_name', 255)->nullable()->after('alternate_phone');
            }
            if (! Schema::hasColumn('vendors', 'account_name')) {
                $table->string('account_name', 255)->nullable()->after('bank_name');
            }
            if (! Schema::hasColumn('vendors', 'account_number')) {
                $table->string('account_number', 64)->nullable()->after('account_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            foreach (['bank_name', 'account_name', 'account_number'] as $column) {
                if (Schema::hasColumn('vendors', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
