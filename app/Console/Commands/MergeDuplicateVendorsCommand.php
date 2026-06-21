<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Services\VendorMergeService;
use Illuminate\Console\Command;

class MergeDuplicateVendorsCommand extends Command
{
    protected $signature = 'vendors:merge-duplicates
                            {--list : List duplicate vendor groups without merging}
                            {--name= : Filter by company name (partial match)}
                            {--canonical= : Keep this vendor code (e.g. V100)}
                            {--merge= : Comma-separated vendor codes to merge into canonical}
                            {--auto : Merge all duplicate name groups automatically}
                            {--dry-run : Preview changes without saving (default unless --force)} 
                            {--force : Apply merges}';

    protected $description = 'Merge duplicate vendor directory records created before manual-PO dedupe (safe: reassigns references, deactivates duplicates)';

    public function handle(VendorMergeService $mergeService): int
    {
        $dryRun = ! $this->option('force');
        $nameFilter = $this->option('name');

        if ($this->option('canonical') && $this->option('merge')) {
            return $this->mergeExplicit($mergeService, $dryRun);
        }

        $groups = $mergeService->findDuplicateGroups(is_string($nameFilter) ? $nameFilter : null);

        if ($groups->isEmpty()) {
            $this->info('No duplicate vendor groups found.');

            return self::SUCCESS;
        }

        if ($this->option('list') || (! $this->option('auto') && ! $this->option('force'))) {
            $this->warn($dryRun ? 'Dry run / list mode — no changes will be saved. Pass --force to apply.' : 'Listing duplicate groups:');

            foreach ($groups as $normalizedName => $group) {
                $canonical = $mergeService->pickCanonical($group);
                $this->newLine();
                $this->line('<fg=cyan>'.$group->first()->name.'</> ('.$group->count().' records)');
                $this->line('  Keep: '.$canonical->vendor_id.' | email: '.($canonical->email ?: '—').' | status: '.$canonical->status);
                $this->line('  Duplicates: '.$group->where('id', '!=', $canonical->id)->pluck('vendor_id')->implode(', '));
                $this->line('  Suggested command:');
                $dupes = $group->where('id', '!=', $canonical->id)->pluck('vendor_id')->implode(',');
                $this->line('    php artisan vendors:merge-duplicates --canonical='.$canonical->vendor_id.' --merge='.$dupes.' --force');
            }

            return self::SUCCESS;
        }

        if (! $this->option('auto')) {
            $this->error('Use --list to preview, --auto --force to merge all groups, or --canonical + --merge --force for one group.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Auto mode requires --force to apply merges. Re-run with --auto --force');
        }

        $totalMerged = 0;

        foreach ($groups as $group) {
            $canonical = $mergeService->pickCanonical($group);
            $result = $mergeService->mergeGroup($canonical, $group, $dryRun);

            $this->info(($dryRun ? '[DRY RUN] Would keep ' : 'Kept ')
                .$result['canonical']->vendor_id
                .' and merge: '.implode(', ', $result['merged']));

            if ($result['skipped'] !== []) {
                $this->warn('Skipped: '.implode('; ', $result['skipped']));
            }

            $totalMerged += count($result['merged']);
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would merge ' : 'Merged ').$totalMerged.' duplicate vendor record(s).');

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

        $this->info(($dryRun ? '[DRY RUN] Would keep ' : 'Kept ')
            .$result['canonical']->vendor_id
            .' ('.$result['canonical']->name.')');
        $this->info('Merged duplicates: '.implode(', ', $result['merged']));

        return self::SUCCESS;
    }
}
