<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing users created/edited with the catch-all `logistics` role need to be
 * promoted to the canonical `logistics_manager` value so that role middleware
 * (`role:logistics_manager`) and capability flags in PermissionService treat
 * them as managers (which the admin dropdown labels them as).
 *
 * No rollback path is provided because we can't tell which legacy rows were
 * intended as officers vs managers; the down migration is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'logistics')
            ->update(['role' => 'logistics_manager']);
    }

    public function down(): void
    {
        // Intentionally a no-op: we won't downgrade managers back to the
        // ambiguous legacy `logistics` value.
    }
};
