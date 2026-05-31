<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'scm_transaction_id')) {
                $table->uuid('scm_transaction_id')->nullable()->after('mrf_id');
            }
        });

        DB::table('m_r_f_s')
            ->whereNull('scm_transaction_id')
            ->orderBy('id')
            ->lazyById()
            ->each(function ($row) {
                DB::table('m_r_f_s')
                    ->where('id', $row->id)
                    ->update(['scm_transaction_id' => (string) Str::uuid()]);
            });

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->unique('scm_transaction_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE m_r_f_s ALTER COLUMN scm_transaction_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'scm_transaction_id')) {
                $table->dropUnique(['scm_transaction_id']);
                $table->dropColumn('scm_transaction_id');
            }
        });
    }
};
