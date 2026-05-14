<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_registrations') && ! Schema::hasColumn('vendor_registrations', 'category_other')) {
            Schema::table('vendor_registrations', function (Blueprint $table) {
                $table->string('category_other', 500)->nullable()->after('category');
            });
        }

        if (Schema::hasTable('vendors') && ! Schema::hasColumn('vendors', 'category_other')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('category_other', 500)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendor_registrations') && Schema::hasColumn('vendor_registrations', 'category_other')) {
            Schema::table('vendor_registrations', function (Blueprint $table) {
                $table->dropColumn('category_other');
            });
        }

        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'category_other')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('category_other');
            });
        }
    }
};
