<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendors')) {
            return;
        }

        if (! Schema::hasColumn('vendors', 'quickbooks_vendor_id')) {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS quickbooks_vendor_id VARCHAR(64) NULL');
            } else {
                DB::statement('ALTER TABLE vendors ADD COLUMN quickbooks_vendor_id VARCHAR(64) NULL');
            }
        }

        DB::statement('CREATE INDEX IF NOT EXISTS vendors_quickbooks_vendor_id_idx ON vendors (quickbooks_vendor_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('vendors')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS vendors_quickbooks_vendor_id_idx');

        if (Schema::hasColumn('vendors', 'quickbooks_vendor_id')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('quickbooks_vendor_id');
            });
        }
    }
};
