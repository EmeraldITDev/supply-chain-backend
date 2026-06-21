<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Services\VendorMergeService;
use Illuminate\Console\Command;

class MergeDuplicateVendorsCommand extends Command
{
    protected $signature = 'vendors:merge-duplicates
                            {--list : List unresolved duplicate vendor groups (Active rows only)}
                            {--name= : Filter by company name (partial match)}
                            {--canonical= : Keep this vendor code (e.g. V100)}
                            {--merge= : Comma-separated vendor codes to merge into canonical}
                            {--auto : Merge all unresolved duplicate groups automatically}
                            {--repair-inactive : Set Inactive on Active rows whose notes say Merged into}
                            {--purge-merged : Delete Inactive rows already merged (no remaining references)}
                            {--dry-run : Preview changes without saving (default unless --force)}
                            {--force : Apply changes}';

    protected $description = 'Merge duplicate vendor directory records (reassigns references, deactivates duplicates, optional purge)';

    public function handle(VendorMergeService $mergeService): int
    {
        $dryRun = ! $this->option('force');

        if ($this->option('repair-inactive')) {
            return $this->repairMergedInactive($dryRun);
        }

        if ($this->option('purge-merged')) {
            return $this->purgeMerged($mergeService, $dryRun);
        }

        $nameFilter = $this->option('name');

        if ($this->option('canonical') && $this->option('merge')) {
            return $this->mergeExplicit($mergeService, $dryRun);
        }

        $groups = $mergeService->findDuplicateGroups(is_string($nameFilter) ? $nameFilter : null, true);

        if ($groups->isEmpty()) {
            $this->info('No unresolved duplicate vendor groups (Active rows with the same name).');
            $this->line('If inactive merged rows remain in the DB, run: php artisan vendors:merge-duplicates --purge-merged --force');

            return self::SUCCESS;
        }

        if ($this->option('list') || (! $this->option('auto') && ! $this->option('force'))) {
            $this->warn($dryRun
                ? 'Preview mode — pass --force with --canonical/--merge or --auto to apply.'
                : 'Listing unresolved duplicate groups (Active only):');

            foreach ($groups as $group) {
                $this->printGroupSuggestion($mergeService, $group);
            }

            return self::SUCCESS;
        }

        if (! $this->option('auto')) {
            $this->error('Use --list to preview, --auto --force to merge all groups, or --canonical + --merge --force for one group.');

            return self::FAILURE;
        }

        $totalMerged = 0;

        foreach ($groups as $group) {
            $canonical = $mergeService->pickCanonical($group);
            $result = $mergeService->mergeGroup($canonical, $group, $dryRun);

            $this->printMergeResult($result, $dryRun);
            $totalMerged += count($result['merged']);
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would merge ' : 'Merged ').$totalMerged.' duplicate vendor record(s).');
        $this->line('Next: php artisan vendors:merge-duplicates --purge-merged --force');

        return self::SUCCESS;
    }

    private function printGroupSuggestion(VendorMergeService $mergeService, $group): void
    {
        $canonical = $mergeService->pickCanonical($group);
        $activeDupes = $group->where('id', '!=', $canonical->id)->values();

        $this->newLine();
        $this->line('<fg=cyan>'.$group->first()->name.'</> ('.$group->count().' active records)');
        $this->line('  Keep: '.$canonical->vendor_id
            .' | email: '.($canonical->email ?: '—')
            .' | status: '.$canonical->status);
        $this->line('  Merge: '.$activeDupes->pluck('vendor_id')->implode(', '));
        $this->line('  Command:');
        $dupes = $activeDupes->pluck('vendor_id')->implode(',');
        $this->line('    php artisan vendors:merge-duplicates --canonical='.$canonical->vendor_id.' --merge='.$dupes.' --force');
        $this->line('    php artisan vendors:merge-duplicates --purge-merged --force');
    }

    /**
     * @param  array{canonical: Vendor, merged: list<string>, skipped: list<string>, already_merged: list<string>}  $result
     */
    private function printMergeResult(array $result, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY RUN] Would keep ' : 'Kept ';
        $this->info($prefix.$result['canonical']->vendor_id.' ('.$result['canonical']->name.')');

        if ($result['merged'] !== []) {
            $this->info(($dryRun ? 'Would merge: ' : 'Merged: ').implode(', ', $result['merged']));
        }
        if ($result['already_merged'] !== []) {
            $this->line('Already inactive/merged: '.implode(', ', $result['already_merged']));
        }
        if ($result['skipped'] !== []) {
            $this->warn('Skipped: '.implode('; ', $result['skipped']));
        }
    }

    private function repairMergedInactive(bool $dryRun): int
    {
        $rows = Vendor::query()
            ->where('status', '!=', 'Inactive')
            ->where('notes', 'like', '%Merged into V%')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No merged vendor rows need Inactive repair.');

            return self::SUCCESS;
        }

        foreach ($rows as $vendor) {
            $this->line(($dryRun ? '[DRY RUN] Would deactivate ' : 'Deactivated ')
                .$vendor->vendor_id.' ('.$vendor->name.')');
            if (! $dryRun) {
                $vendor->update(['status' => 'Inactive']);
            }
        }

        $this->info(($dryRun ? 'Would repair ' : 'Repaired ').$rows->count().' row(s).');

        return self::SUCCESS;
    }

    private function purgeMerged(VendorMergeService $mergeService, bool $dryRun): int
    {
        if ($dryRun) {
            $this->warn('Dry run — pass --force to delete inactive merged rows.');
        }

        $result = $mergeService->purgeInactiveMergedDuplicates($dryRun);

        if ($result['deleted'] !== []) {
            $this->info(($dryRun ? 'Would delete ' : 'Deleted ')
                .count($result['deleted']).' inactive merged row(s): '
                .implode(', ', $result['deleted']));
        } else {
            $this->info('No inactive merged rows eligible for deletion.');
        }

        if ($result['skipped'] !== []) {
            $this->warn('Skipped: '.implode('; ', $result['skipped']));
        }

        return self::SUCCESS;
    }

    private function mergeExplicit(VendorMergeService $mergeService, bool $dryRun): int
    {
        $canonicalCode = (string) $this->option('canonical');
        $mergeCodes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('merge')))));

        if ($mergeCodes === []) {
            $this->error('Provide --merge=V124,V127,...');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run — pass --force to apply.');
        }

        $canonical = Vendor::query()->where('vendor_id', $canonicalCode)->first();
        if (! $canonical) {
            $this->error("Canonical vendor {$canonicalCode} not found.");

            return self::FAILURE;
        }

        $duplicates = Vendor::query()->whereIn('vendor_id', $mergeCodes)->get();
        $missing = array_diff($mergeCodes, $duplicates->pluck('vendor_id')->all());
        if ($missing !== []) {
            $this->error('Unknown vendor codes: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $result = $mergeService->mergeGroup($canonical, $duplicates->push($canonical), $dryRun);
        $this->printMergeResult($result, $dryRun);

        if (! $dryRun && $result['merged'] !== []) {
            $this->line('Run: php artisan vendors:merge-duplicates --purge-merged --force');
        }

        return self::SUCCESS;
    }
}
