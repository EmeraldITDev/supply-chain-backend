<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some hosts failed to apply `2026_05_12_220000_relax_trip_vendor_submissions_nullable`
 * when `->nullable()->change()` could not run. Placeholder rows on assign-vendor
 * then fail INSERT (NOT NULL on vehicle/driver fields). This migration uses
 * driver-native ALTER so nullable + index changes apply reliably.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('trip_vendor_submissions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->pgsqlDropNotNullOnPlaceholderColumns();
            $this->pgsqlDropPlateUnique();
            $this->pgsqlEnsurePlateIndex();
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->mysqlDropNotNullOnPlaceholderColumns();
            $this->mysqlDropPlateUnique();
            $this->mysqlEnsurePlateIndex();
        } else {
            $this->fallbackSchemaChange();
        }
    }

    private function table(): string
    {
        return Schema::getConnection()->getTablePrefix().'trip_vendor_submissions';
    }

    private function pgsqlDropNotNullOnPlaceholderColumns(): void
    {
        $t = $this->table();
        $cols = ['vehicle_make', 'vehicle_model', 'plate_number', 'driver_name', 'driver_phone', 'driver_license_no'];
        foreach ($cols as $col) {
            try {
                DB::statement('ALTER TABLE "'.$t.'" ALTER COLUMN "'.$col.'" DROP NOT NULL');
            } catch (\Throwable) {
                // Already nullable or column missing
            }
        }
    }

    private function pgsqlDropPlateUnique(): void
    {
        $t = $this->table();
        foreach (['trip_vendor_submissions_plate_number_unique', 'trip_vendor_submissions_plate_number_key'] as $name) {
            try {
                DB::statement('ALTER TABLE "'.$t.'" DROP CONSTRAINT IF EXISTS "'.$name.'"');
            } catch (\Throwable) {
            }
        }
        try {
            DB::statement('DROP INDEX IF EXISTS trip_vendor_submissions_plate_number_unique');
        } catch (\Throwable) {
        }
    }

    private function pgsqlEnsurePlateIndex(): void
    {
        try {
            Schema::table('trip_vendor_submissions', function (Blueprint $table) {
                $table->index('plate_number', 'trip_vendor_submissions_plate_number_index');
            });
        } catch (\Throwable) {
        }
    }

    private function mysqlDropNotNullOnPlaceholderColumns(): void
    {
        $t = $this->table();
        $cols = ['vehicle_make', 'vehicle_model', 'plate_number', 'driver_name', 'driver_phone', 'driver_license_no'];
        foreach ($cols as $col) {
            $row = DB::selectOne(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$t, $col]
            );
            if (!$row || empty($row->COLUMN_TYPE)) {
                continue;
            }
            $type = $row->COLUMN_TYPE;
            try {
                DB::statement('ALTER TABLE `'.$t.'` MODIFY `'.$col.'` '.$type.' NULL');
            } catch (\Throwable) {
            }
        }
    }

    private function mysqlDropPlateUnique(): void
    {
        $t = $this->table();
        foreach (['trip_vendor_submissions_plate_number_unique'] as $indexName) {
            try {
                DB::statement('ALTER TABLE `'.$t.'` DROP INDEX `'.$indexName.'`');
            } catch (\Throwable) {
            }
        }
    }

    private function mysqlEnsurePlateIndex(): void
    {
        try {
            Schema::table('trip_vendor_submissions', function (Blueprint $table) {
                $table->index('plate_number', 'trip_vendor_submissions_plate_number_index');
            });
        } catch (\Throwable) {
        }
    }

    private function fallbackSchemaChange(): void
    {
        try {
            Schema::table('trip_vendor_submissions', function (Blueprint $table) {
                $table->string('vehicle_make')->nullable()->change();
                $table->string('vehicle_model')->nullable()->change();
                $table->string('plate_number')->nullable()->change();
                $table->string('driver_name')->nullable()->change();
                $table->string('driver_phone')->nullable()->change();
                $table->string('driver_license_no')->nullable()->change();
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('trip_vendor_submissions', function (Blueprint $table) {
                $table->dropUnique('trip_vendor_submissions_plate_number_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('trip_vendor_submissions', function (Blueprint $table) {
                $table->index('plate_number', 'trip_vendor_submissions_plate_number_index');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
    }
};
