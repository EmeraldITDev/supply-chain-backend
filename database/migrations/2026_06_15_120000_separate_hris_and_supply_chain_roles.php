<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'hris_role')) {
                $table->string('hris_role')->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'supply_chain_role')) {
                $table->string('supply_chain_role')->nullable()->after('hris_role');
            }
        });

        // Backfill SCM roles from the legacy shared column. HRIS owns hris_role separately.
        // Skip HRIS-only values — those belong on hris_role, not supply_chain_role.
        DB::table('users')
            ->whereNull('supply_chain_role')
            ->whereNotNull('role')
            ->where('role', '!=', '')
            ->whereNotIn('role', [
                'corporate_hr',
                'hr_officer',
                'human_resources',
                'hr_admin',
                'hr_specialist',
                'people_operations',
            ])
            ->update([
                'supply_chain_role' => DB::raw('role'),
            ]);

        DB::table('users')
            ->whereNull('hris_role')
            ->whereIn('role', [
                'corporate_hr',
                'hr_officer',
                'human_resources',
                'hr_admin',
                'hr_specialist',
                'people_operations',
            ])
            ->update([
                'hris_role' => DB::raw('role'),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'supply_chain_role')) {
                $table->dropColumn('supply_chain_role');
            }
            if (Schema::hasColumn('users', 'hris_role')) {
                $table->dropColumn('hris_role');
            }
        });
    }
};
