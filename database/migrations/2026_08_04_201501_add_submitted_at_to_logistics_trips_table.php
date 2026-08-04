<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL');
            DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS logistics_recommendation TEXT NULL');
            DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS escort_personnel_count SMALLINT NULL');

            return;
        }

        Schema::table('logistics_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_trips', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }

            if (! Schema::hasColumn('logistics_trips', 'logistics_recommendation')) {
                $table->text('logistics_recommendation')->nullable();
            }

            if (! Schema::hasColumn('logistics_trips', 'escort_personnel_count')) {
                $table->unsignedSmallInteger('escort_personnel_count')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE logistics_trips DROP COLUMN IF EXISTS submitted_at');
            DB::statement('ALTER TABLE logistics_trips DROP COLUMN IF EXISTS logistics_recommendation');
            DB::statement('ALTER TABLE logistics_trips DROP COLUMN IF EXISTS escort_personnel_count');

            return;
        }

        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropColumnIfExists('submitted_at');
            $table->dropColumnIfExists('logistics_recommendation');
            $table->dropColumnIfExists('escort_personnel_count');
        });
    }
};
