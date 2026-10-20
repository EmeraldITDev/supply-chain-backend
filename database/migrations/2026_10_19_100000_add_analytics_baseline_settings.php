<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive analytics-baseline configuration stored in system_settings.
 * Adds the 'analytics_baseline' category rows — no existing rows modified.
 *
 * Keys written by EstablishAnalyticsBaseline command:
 *   analytics_baseline / effective_at       — ISO-8601 timestamp of the baseline cutover
 *   analytics_baseline / timezone           — IANA timezone the timestamp was recorded in
 *   analytics_baseline / version_ref        — git SHA / deploy tag for traceability
 *   analytics_baseline / established_by     — actor name/email
 *   analytics_baseline / established_at     — when the command was actually run
 *
 * system_settings already exists (2026_10_13_120000_create_system_settings_table).
 * This migration simply verifies uniqueness index presence and seeds
 * an empty-value placeholder row so integrations can detect the missing baseline.
 */
return new class extends Migration
{
    public function up(): void
    {
        // system_settings table already created — nothing to create.
        // Ensure the unique index is present (idempotent guard).
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS system_settings_category_key_unique ON system_settings (category, key)',
            );
        }

        // Seed a sentinel row so application code can detect an un-established baseline
        // without a missing-row exception. The 'effective_at' value is NULL until the
        // scm:establish-analytics-baseline command runs at production cutover.
        $exists = DB::table('system_settings')
            ->where('category', 'analytics_baseline')
            ->where('key', 'effective_at')
            ->exists();

        if (! $exists) {
            DB::table('system_settings')->insert([
                'category'   => 'analytics_baseline',
                'key'        => 'effective_at',
                'value'      => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove only the sentinel row; the table itself was not created here.
        DB::table('system_settings')
            ->where('category', 'analytics_baseline')
            ->delete();
    }
};
