<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\MRF;
use App\Services\ScmAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One-time correction of a single production MRF display/reference id.
 *
 * Exact target:
 *   Old: MRF-OANDO-PRC-EQP-2026-256
 *   New: MRF-EMERALD-PRC-EQP-2026-256
 *
 * Verifies identity via title / status / category / department / estimated cost.
 * Default is dry-run. Pass --apply to persist inside a locked transaction.
 * Does not change workflow state, send emails, regenerate PDFs, or touch the
 * MRF numbering generator.
 *
 * Usage (cPanel PHP 8.2 example — adjust path to your artisan entrypoint):
 *   /usr/local/bin/php /home/USER/supply-chain-backend/artisan scm:correct-mrf-reference
 *   /usr/local/bin/php /home/USER/supply-chain-backend/artisan scm:correct-mrf-reference --apply
 */
class CorrectMrfReference extends Command
{
    public const OLD_REFERENCE = 'MRF-OANDO-PRC-EQP-2026-256';

    public const NEW_REFERENCE = 'MRF-EMERALD-PRC-EQP-2026-256';

    public const EXPECTED_TITLE = 'COST OF INCORPORATION OF VALADVISORS AFRICA LIMITED';

    public const EXPECTED_STATUS = 'pending_revision';

    public const EXPECTED_CATEGORY = 'equipment';

    public const EXPECTED_DEPARTMENT = 'Procurement';

    public const EXPECTED_ESTIMATED_COST = 2150000.0;

    protected $signature = 'scm:correct-mrf-reference
                            {--apply : Persist the reference correction (default is dry-run)}';

    protected $description = 'One-time correction of MRF-OANDO-PRC-EQP-2026-256 → MRF-EMERALD-PRC-EQP-2026-256 (dry-run by default)';

    public function handle(ScmAuditService $audit): int
    {
        $apply = (bool) $this->option('apply');

        $mrf = MRF::query()
            ->where(function ($query) {
                $query->where('formatted_id', self::OLD_REFERENCE)
                    ->orWhere('mrf_id', self::OLD_REFERENCE);
            })
            ->first();

        if (! $mrf) {
            // Idempotent path: already corrected to the new reference.
            $mrf = MRF::query()
                ->where(function ($query) {
                    $query->where('formatted_id', self::NEW_REFERENCE)
                        ->orWhere('mrf_id', self::NEW_REFERENCE);
                })
                ->first();
        }

        if (! $mrf) {
            $this->error(sprintf(
                'No MRF found with reference %s (or already-corrected %s). Refusing.',
                self::OLD_REFERENCE,
                self::NEW_REFERENCE
            ));

            return self::FAILURE;
        }

        $identityIssues = $this->identityIssues($mrf);
        $authoritative = $this->detectAuthoritativeColumns($mrf);
        $related = $this->inspectRelated($mrf);
        $alreadyCorrected = $this->isAlreadyCorrected($mrf, $authoritative);

        $this->table(
            ['Field', 'Value'],
            [
                ['numeric_id (immutable PK)', (string) $mrf->id],
                ['mrf_id (legacy string)', (string) ($mrf->mrf_id ?? '—')],
                ['formatted_id (rich display id)', (string) ($mrf->formatted_id ?? '—')],
                ['scm_transaction_id (immutable)', (string) ($mrf->scm_transaction_id ?? '—')],
                ['title', (string) ($mrf->title ?? '—')],
                ['status', (string) ($mrf->status ?? '—')],
                ['workflow_state', (string) ($mrf->workflow_state ?? '—')],
                ['category', (string) ($mrf->category ?? '—')],
                ['department', (string) ($mrf->department ?? '—')],
                ['estimated_cost', $mrf->estimated_cost !== null ? (string) $mrf->estimated_cost : '—'],
                ['revision_number', (string) ((int) ($mrf->revision_number ?? 0))],
                ['po_number', filled($mrf->po_number) ? (string) $mrf->po_number : '—'],
                ['linked_po_id', filled($mrf->linked_po_id) ? (string) $mrf->linked_po_id : '—'],
                ['columns_holding_old_ref', $authoritative['holding_old'] !== [] ? implode(', ', $authoritative['holding_old']) : '(none)'],
                ['columns_holding_new_ref', $authoritative['holding_new'] !== [] ? implode(', ', $authoritative['holding_new']) : '(none)'],
                ['mode', $apply ? 'APPLY' : 'DRY-RUN'],
            ]
        );

        $this->line('Related record scan (numeric FKs are left unchanged):');
        foreach ($related['summary'] as $line) {
            $this->line('  • '.$line);
        }

        if ($related['report_only'] !== []) {
            $this->warn('Historical / external references requiring separate handling (not auto-updated):');
            foreach ($related['report_only'] as $line) {
                $this->line('  • '.$line);
            }
        }

        if ($identityIssues !== [] && ! $alreadyCorrected) {
            $this->error('Identity verification failed. Refusing.');
            foreach ($identityIssues as $issue) {
                $this->line('  • '.$issue);
            }

            return self::FAILURE;
        }

        if ($alreadyCorrected) {
            $this->info('Reference already corrected on authoritative columns. Nothing to change (idempotent).');
            Log::info('scm:correct-mrf-reference already applied', [
                'mrf_row_id' => $mrf->id,
                'mrf_id' => $mrf->mrf_id,
                'formatted_id' => $mrf->formatted_id,
                'apply' => $apply,
            ]);

            return self::SUCCESS;
        }

        if ($authoritative['holding_old'] === []) {
            $this->error('Old reference is not present on mrf_id/formatted_id for this row. Refusing.');

            return self::FAILURE;
        }

        $collision = $this->newReferenceCollision($mrf);
        if ($collision !== null) {
            $this->error($collision);

            return self::FAILURE;
        }

        $this->line('Planned authoritative updates:');
        foreach ($authoritative['holding_old'] as $column) {
            $this->line(sprintf(
                '  • m_r_f_s.%s: %s → %s',
                $column,
                self::OLD_REFERENCE,
                self::NEW_REFERENCE
            ));
        }
        if ($related['will_update'] !== []) {
            $this->line('Planned dependent string updates (consistency only):');
            foreach ($related['will_update'] as $line) {
                $this->line('  • '.$line);
            }
        }

        if (! $apply) {
            $this->warn('Dry run only — no records were changed. Re-run with --apply to persist.');
            Log::info('scm:correct-mrf-reference dry-run', [
                'mrf_row_id' => $mrf->id,
                'old_reference' => self::OLD_REFERENCE,
                'new_reference' => self::NEW_REFERENCE,
                'columns' => $authoritative['holding_old'],
                'dependent_updates' => $related['will_update'],
                'report_only' => $related['report_only'],
            ]);

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($mrf, $authoritative, $related, $audit) {
                /** @var MRF $locked */
                $locked = MRF::query()->whereKey($mrf->id)->lockForUpdate()->first();
                if (! $locked) {
                    throw new \RuntimeException('MRF row disappeared under lock.');
                }

                // Re-verify under lock.
                $issues = $this->identityIssues($locked);
                if ($issues !== []) {
                    throw new \RuntimeException('Identity verification failed under lock: '.implode('; ', $issues));
                }

                $live = $this->detectAuthoritativeColumns($locked);
                if ($live['holding_old'] === []) {
                    throw new \RuntimeException('Old reference no longer present under lock (concurrent change).');
                }
                if ($this->newReferenceCollision($locked) !== null) {
                    throw new \RuntimeException((string) $this->newReferenceCollision($locked));
                }

                $before = [
                    'mrf_id' => $locked->mrf_id,
                    'formatted_id' => $locked->formatted_id,
                    'linked_po_id' => $locked->linked_po_id,
                    'status' => $locked->status,
                    'workflow_state' => $locked->workflow_state,
                    'revision_number' => $locked->revision_number,
                ];

                $update = [];
                foreach ($live['holding_old'] as $column) {
                    $update[$column] = self::NEW_REFERENCE;
                }
                if (filled($locked->linked_po_id) && (string) $locked->linked_po_id === self::OLD_REFERENCE) {
                    $update['linked_po_id'] = self::NEW_REFERENCE;
                }

                $query = MRF::query()->whereKey($locked->id);
                foreach ($live['holding_old'] as $column) {
                    $query->where($column, self::OLD_REFERENCE);
                }

                $updated = $query->update($update);
                if ($updated !== 1) {
                    throw new \RuntimeException('Conditional update affected '.$updated.' rows; aborting.');
                }

                $activitiesUpdated = 0;
                if (
                    in_array('mrf_id', $live['holding_old'], true)
                    && Schema::hasTable('activities')
                    && Schema::hasColumn('activities', 'entity_id')
                ) {
                    $activitiesUpdated = Activity::query()
                        ->where('entity_type', 'mrf')
                        ->where('entity_id', self::OLD_REFERENCE)
                        ->update(['entity_id' => self::NEW_REFERENCE]);
                }

                $fresh = $locked->fresh();
                $after = [
                    'mrf_id' => $fresh?->mrf_id,
                    'formatted_id' => $fresh?->formatted_id,
                    'linked_po_id' => $fresh?->linked_po_id,
                    'status' => $fresh?->status,
                    'workflow_state' => $fresh?->workflow_state,
                    'revision_number' => $fresh?->revision_number,
                ];

                $audit->record(
                    'mrf_reference_corrected',
                    'MRF',
                    self::NEW_REFERENCE,
                    null,
                    sprintf(
                        'One-time Artisan correction of MRF reference %s → %s',
                        self::OLD_REFERENCE,
                        self::NEW_REFERENCE
                    ),
                    [
                        'source' => 'artisan:scm:correct-mrf-reference',
                        'mrf_row_id' => $locked->id,
                        'old_reference' => self::OLD_REFERENCE,
                        'new_reference' => self::NEW_REFERENCE,
                        'columns_updated' => array_keys($update),
                        'activities_entity_id_rows' => $activitiesUpdated,
                        'before' => $before,
                        'after' => $after,
                        'workflow_untouched' => true,
                        'notifications_not_sent' => true,
                        'report_only' => $related['report_only'],
                    ]
                );

                Log::info('scm:correct-mrf-reference applied', [
                    'mrf_row_id' => $locked->id,
                    'columns_updated' => array_keys($update),
                    'activities_updated' => $activitiesUpdated,
                    'before' => $before,
                    'after' => $after,
                ]);
            });
        } catch (\Throwable $e) {
            $this->error('Apply failed: '.$e->getMessage());
            Log::error('scm:correct-mrf-reference apply failed', [
                'mrf_row_id' => $mrf->id,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $fresh = $mrf->fresh();
        $this->info('Correction applied.');
        $this->table(
            ['Field', 'Before', 'After'],
            [
                ['mrf_id', (string) ($mrf->mrf_id ?? '—'), (string) ($fresh?->mrf_id ?? '—')],
                ['formatted_id', (string) ($mrf->formatted_id ?? '—'), (string) ($fresh?->formatted_id ?? '—')],
                ['linked_po_id', (string) ($mrf->linked_po_id ?? '—'), (string) ($fresh?->linked_po_id ?? '—')],
                ['status (unchanged)', (string) ($mrf->status ?? '—'), (string) ($fresh?->status ?? '—')],
                ['workflow_state (unchanged)', (string) ($mrf->workflow_state ?? '—'), (string) ($fresh?->workflow_state ?? '—')],
                ['revision_number (unchanged)', (string) ((int) ($mrf->revision_number ?? 0)), (string) ((int) ($fresh?->revision_number ?? 0))],
                ['estimated_cost (unchanged)', (string) ($mrf->estimated_cost ?? '—'), (string) ($fresh?->estimated_cost ?? '—')],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function identityIssues(MRF $mrf): array
    {
        $issues = [];

        $title = trim((string) ($mrf->title ?? ''));
        if (strcasecmp($title, self::EXPECTED_TITLE) !== 0) {
            $issues[] = sprintf('title expected "%s", got "%s"', self::EXPECTED_TITLE, $title !== '' ? $title : '(empty)');
        }

        $status = strtolower(trim((string) ($mrf->status ?? '')));
        $workflow = strtolower(trim((string) ($mrf->workflow_state ?? '')));
        $pending = self::EXPECTED_STATUS;
        if ($status !== $pending && $workflow !== $pending) {
            $issues[] = sprintf(
                'status/workflow expected %s, got status=%s workflow_state=%s',
                $pending,
                $mrf->status ?: '(empty)',
                $mrf->workflow_state ?: '(empty)'
            );
        }

        $category = strtolower(trim((string) ($mrf->category ?? '')));
        if ($category !== strtolower(self::EXPECTED_CATEGORY)) {
            $issues[] = sprintf('category expected "%s", got "%s"', self::EXPECTED_CATEGORY, $mrf->category ?: '(empty)');
        }

        $department = trim((string) ($mrf->department ?? ''));
        if (strcasecmp($department, self::EXPECTED_DEPARTMENT) !== 0) {
            $issues[] = sprintf('department expected "%s", got "%s"', self::EXPECTED_DEPARTMENT, $department !== '' ? $department : '(empty)');
        }

        $cost = $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null;
        if ($cost === null || abs($cost - self::EXPECTED_ESTIMATED_COST) > 0.009) {
            $issues[] = sprintf(
                'estimated_cost expected %s, got %s',
                number_format(self::EXPECTED_ESTIMATED_COST, 2, '.', ''),
                $cost === null ? '(empty)' : (string) $cost
            );
        }

        return $issues;
    }

    /**
     * @return array{holding_old: list<string>, holding_new: list<string>}
     */
    private function detectAuthoritativeColumns(MRF $mrf): array
    {
        $holdingOld = [];
        $holdingNew = [];

        foreach (['formatted_id', 'mrf_id'] as $column) {
            $value = trim((string) ($mrf->{$column} ?? ''));
            if ($value === self::OLD_REFERENCE) {
                $holdingOld[] = $column;
            }
            if ($value === self::NEW_REFERENCE) {
                $holdingNew[] = $column;
            }
        }

        return [
            'holding_old' => $holdingOld,
            'holding_new' => $holdingNew,
        ];
    }

    /**
     * @param  array{holding_old: list<string>, holding_new: list<string>}  $authoritative
     */
    private function isAlreadyCorrected(MRF $mrf, array $authoritative): bool
    {
        if ($authoritative['holding_old'] !== []) {
            return false;
        }

        return $authoritative['holding_new'] !== [];
    }

    private function newReferenceCollision(MRF $mrf): ?string
    {
        $other = MRF::query()
            ->where('id', '!=', $mrf->id)
            ->where(function ($query) {
                $query->where('formatted_id', self::NEW_REFERENCE)
                    ->orWhere('mrf_id', self::NEW_REFERENCE);
            })
            ->first(['id', 'mrf_id', 'formatted_id']);

        if (! $other) {
            return null;
        }

        return sprintf(
            'New reference %s already exists on another MRF (numeric id=%s, mrf_id=%s, formatted_id=%s). Refusing.',
            self::NEW_REFERENCE,
            $other->id,
            $other->mrf_id,
            $other->formatted_id
        );
    }

    /**
     * @return array{
     *   summary: list<string>,
     *   will_update: list<string>,
     *   report_only: list<string>
     * }
     */
    private function inspectRelated(MRF $mrf): array
    {
        $summary = [];
        $willUpdate = [];
        $reportOnly = [];

        $numericId = (int) $mrf->id;
        $old = self::OLD_REFERENCE;

        foreach (
            [
                'mrf_approval_history' => 'mrf_id',
                'mrf_line_items' => 'mrf_id',
                'mrf_items' => 'mrf_id',
                'r_f_q_s' => 'mrf_id',
                'quotations' => 'mrf_id',
                'procurement_documents' => 'mrf_id',
                'payment_schedules' => 'mrf_id',
                'finance_sync_events' => 'mrf_id',
                'price_comparisons' => 'purchase_order_id',
            ] as $table => $column
        ) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $count = DB::table($table)->where($column, $numericId)->count();
            $summary[] = sprintf('%s.%s → numeric PK %d (%d row(s), left unchanged)', $table, $column, $numericId, $count);
        }

        if (Schema::hasTable('activities') && Schema::hasColumn('activities', 'entity_id')) {
            $activityHits = Activity::query()
                ->where('entity_type', 'mrf')
                ->where('entity_id', $old)
                ->count();
            $summary[] = sprintf('activities.entity_id = old ref (%d row(s))', $activityHits);
            if ($activityHits > 0 && trim((string) ($mrf->mrf_id ?? '')) === $old) {
                $willUpdate[] = sprintf(
                    'activities.entity_id %s → %s (%d row(s); required because mrf_id is changing)',
                    $old,
                    self::NEW_REFERENCE,
                    $activityHits
                );
            } elseif ($activityHits > 0) {
                $reportOnly[] = sprintf(
                    'activities.entity_id still stores %s (%d row(s)) but mrf_id is not that string — review manually',
                    $old,
                    $activityHits
                );
            }
        }

        if (Schema::hasTable('audit_logs')) {
            $auditHits = AuditLog::query()
                ->where('entity_id', $old)
                ->count();
            if ($auditHits > 0) {
                $reportOnly[] = sprintf(
                    'audit_logs.entity_id = %s (%d historical row(s)) — leave as forensic history; new correction audit will be appended',
                    $old,
                    $auditHits
                );
            }
        }

        if (Schema::hasTable('notifications')) {
            $notifHits = DB::table('notifications')
                ->where('data', 'like', '%'.$old.'%')
                ->count();
            if ($notifHits > 0) {
                $reportOnly[] = sprintf(
                    'notifications.data embeds %s (%d row(s)) — do not rewrite silently',
                    $old,
                    $notifHits
                );
            }
        }

        if (Schema::hasTable('finance_sync_events') && Schema::hasColumn('finance_sync_events', 'request_payload')) {
            $finHits = DB::table('finance_sync_events')
                ->where('mrf_id', $numericId)
                ->where(function ($q) use ($old) {
                    $q->where('request_payload', 'like', '%'.$old.'%')
                        ->orWhere('response_payload', 'like', '%'.$old.'%');
                })
                ->count();
            if ($finHits > 0) {
                $reportOnly[] = sprintf(
                    'finance_sync_events payloads embed %s (%d row(s)) — Finance AP packages need separate handling; scm_transaction_id stays immutable',
                    $old,
                    $finHits
                );
            }
        }

        if (filled($mrf->unsigned_po_url) || filled($mrf->signed_po_url)) {
            $reportOnly[] = 'PO PDF URLs are set (unsigned/signed) — issued document text is not rewritten by this command';
        }

        if (Schema::hasTable('procurement_documents')) {
            $docPathHits = DB::table('procurement_documents')
                ->where('mrf_id', $numericId)
                ->where(function ($q) use ($old) {
                    $q->where('file_path', 'like', '%'.$old.'%')
                        ->orWhere('metadata', 'like', '%'.$old.'%');
                })
                ->count();
            if ($docPathHits > 0) {
                $reportOnly[] = sprintf(
                    'procurement_documents path/metadata embeds %s (%d row(s)) — storage paths / GRN numbers need separate ops handling',
                    $old,
                    $docPathHits
                );
            }
        }

        if (is_array($mrf->revision_snapshot) || is_array($mrf->revision_history)) {
            $blob = json_encode([
                'revision_snapshot' => $mrf->revision_snapshot,
                'revision_history' => $mrf->revision_history,
            ]) ?: '';
            if (str_contains($blob, $old)) {
                $reportOnly[] = 'revision_snapshot / revision_history JSON embeds the old reference string — report only (PO revision snapshots do not key off MRF display id)';
            } else {
                $summary[] = 'revision_snapshot / revision_history: no old reference string found';
            }
        }

        if (filled($mrf->linked_po_id) && (string) $mrf->linked_po_id === $old) {
            $willUpdate[] = sprintf('m_r_f_s.linked_po_id %s → %s', $old, self::NEW_REFERENCE);
        }

        if ($summary === []) {
            $summary[] = 'No related tables detected in this schema snapshot.';
        }

        return [
            'summary' => $summary,
            'will_update' => $willUpdate,
            'report_only' => $reportOnly,
        ];
    }
}
