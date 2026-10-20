<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive analytics classification columns on m_r_f_s.
 *
 * SAFETY: All columns are nullable with no default so every existing row
 * retains a NULL value — equivalent to 'unclassified'. No existing data
 * is overwritten. The application code treats NULL as 'unreconciled_legacy'
 * for records with created_at before the baseline, and 'new_period' for
 * records created after the baseline (default classification rule).
 *
 * These columns affect PERFORMANCE KPI POPULATIONS ONLY.
 * Operational queues (pending_for_role, SCD/exec dashboards) query the
 * workflow_state and approval columns as before — these new fields are
 * invisible to them.
 */
return new class extends Migration
{
    private const TABLE = 'm_r_f_s';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            // ── Analytics classification ────────────────────────────────────
            if (! Schema::hasColumn(self::TABLE, 'analytics_class')) {
                // Values: null (unclassified) | 'new_period' | 'verified_historical' | 'unreconciled_legacy'
                $table->string('analytics_class', 32)->nullable()->after('workflow_state');
            }

            // ── Reconciliation metadata ─────────────────────────────────────
            if (! Schema::hasColumn(self::TABLE, 'reconciliation_status')) {
                // Values: null | 'pending' | 'in_progress' | 'completed' | 'flagged'
                $table->string('reconciliation_status', 32)->nullable()->after('analytics_class');
            }

            if (! Schema::hasColumn(self::TABLE, 'original_business_date')) {
                // The real-world date the activity happened, when different from created_at.
                // Filled during reconciliation; never overwrites created_at.
                $table->date('original_business_date')->nullable()->after('reconciliation_status');
            }

            if (! Schema::hasColumn(self::TABLE, 'handled_offline')) {
                // True if the business activity was managed outside the SCM.
                $table->boolean('handled_offline')->nullable()->after('original_business_date');
            }

            if (! Schema::hasColumn(self::TABLE, 'reconciled_by')) {
                $table->unsignedBigInteger('reconciled_by')->nullable()->after('handled_offline');
            }

            if (! Schema::hasColumn(self::TABLE, 'reconciled_at')) {
                $table->timestamp('reconciled_at')->nullable()->after('reconciled_by');
            }

            if (! Schema::hasColumn(self::TABLE, 'reconciliation_notes')) {
                $table->text('reconciliation_notes')->nullable()->after('reconciled_at');
            }
        });

        // Index on analytics_class only — the column is low-cardinality and rarely filtered
        // outside the analytics path so a single index is sufficient.
        $driver = Schema::getConnection()->getDriverName();
        $indexName = 'mrfs_analytics_class_idx';

        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON m_r_f_s (analytics_class) WHERE analytics_class IS NOT NULL",
            );
        } elseif (! $this->mysqlIndexExists($indexName)) {
            DB::statement("CREATE INDEX {$indexName} ON m_r_f_s (analytics_class)");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $columns = [
                'analytics_class',
                'reconciliation_status',
                'original_business_date',
                'handled_offline',
                'reconciled_by',
                'reconciled_at',
                'reconciliation_notes',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn(self::TABLE, $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function mysqlIndexExists(string $indexName): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [self::TABLE, $indexName],
            );

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }
};
