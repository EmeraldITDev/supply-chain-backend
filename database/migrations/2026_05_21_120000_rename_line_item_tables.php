<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mrf_items') && !Schema::hasTable('mrf_line_items')) {
            Schema::rename('mrf_items', 'mrf_line_items');
        }

        $this->ensureQuotedAmountColumn('mrf_line_items');

        if (Schema::hasTable('srf_items') && !Schema::hasTable('srf_line_items')) {
            Schema::rename('srf_items', 'srf_line_items');
        }

        $this->ensureQuotedAmountColumn('srf_line_items');
    }

    public function down(): void
    {
        if (Schema::hasTable('mrf_line_items') && !Schema::hasTable('mrf_items')) {
            Schema::rename('mrf_line_items', 'mrf_items');
        }

        if (Schema::hasTable('srf_line_items') && !Schema::hasTable('srf_items')) {
            Schema::rename('srf_line_items', 'srf_items');
        }
    }

    private function ensureQuotedAmountColumn(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (!Schema::hasColumn($table, 'quoted_amount')) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $after = Schema::hasColumn($table, 'budget_amount') ? 'budget_amount' : 'total_price';
                $blueprint->decimal('quoted_amount', 15, 2)->nullable()->after($after);
            });
        }

        if (Schema::hasColumn($table, 'quoted_total')) {
            DB::table($table)
                ->whereNotNull('quoted_total')
                ->whereNull('quoted_amount')
                ->update(['quoted_amount' => DB::raw('quoted_total')]);
        }
    }
};
