<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS logistics_recommendation TEXT NULL');
        DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS escort_personnel_count SMALLINT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE logistics_trips DROP COLUMN IF EXISTS submitted_at');
        DB::statement('ALTER TABLE logistics_trips DROP COLUMN IF EXISTS logistics_recommendation');
        DB::statement('ALTER TABLE logistics_trips DROP COLUMN IF EXISTS escort_personnel_count');
    }
};
