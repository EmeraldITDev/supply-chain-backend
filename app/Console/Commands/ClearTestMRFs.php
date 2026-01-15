<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\RFQ;
use App\Models\RFQItem;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClearTestMRFs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mrfs:clear-test 
                            {--title=* : Filter by title (e.g., "supply of stock")}
                            {--category=* : Filter by category}
                            {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear test MRFs from the system (specifically "supply of stock" MRFs)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $titles = $this->option('title');
        $categories = $this->option('category');
        $force = $this->option('force');

        // Default: Clear "supply of stock" MRFs
        if (empty($titles)) {
            $titles = ['supply of stock'];
        }

        // Build query
        $query = MRF::query();

        if (!empty($titles)) {
            $query->where(function($q) use ($titles) {
                foreach ($titles as $title) {
                    $q->orWhere('title', 'ilike', "%{$title}%");
                    $q->orWhere('description', 'ilike', "%{$title}%");
                }
            });
        }

        if (!empty($categories)) {
            $query->whereIn('category', $categories);
        }

        $mrfs = $query->get();

        if ($mrfs->isEmpty()) {
            $this->info('No MRFs found matching the criteria.');
            return 0;
        }

        $this->info("Found {$mrfs->count()} MRF(s) to delete:");
        foreach ($mrfs as $mrf) {
            $this->line("  - {$mrf->mrf_id}: {$mrf->title} ({$mrf->category})");
        }

        if (!$force && !$this->confirm('Do you want to delete these MRFs?', true)) {
            $this->info('Operation cancelled.');
            return 0;
        }

        DB::beginTransaction();
        try {
            $deletedCount = 0;
            
            foreach ($mrfs as $mrf) {
                // Delete related data
                $this->info("Deleting MRF: {$mrf->mrf_id}...");

                // Delete approval history
                MRFApprovalHistory::where('mrf_id', $mrf->id)->delete();
                $this->line("  - Deleted approval history");

                // Delete related RFQs and quotations
                $rfqs = RFQ::where('mrf_id', $mrf->id)->get();
                foreach ($rfqs as $rfq) {
                    Quotation::where('rfq_id', $rfq->id)->delete();
                    $rfq->vendors()->detach();
                    RFQItem::where('rfq_id', $rfq->id)->delete();
                    $rfq->delete();
                }
                $this->line("  - Deleted related RFQs and quotations");

                // Delete MRF items
                $mrf->items()->delete();
                $this->line("  - Deleted MRF items");

                // Delete associated files (PO, GRN, PFI)
                $this->deleteMRFFiles($mrf);

                // Delete the MRF
                $mrf->delete();
                $deletedCount++;
                $this->line("  ✓ MRF {$mrf->mrf_id} deleted successfully");
            }

            DB::commit();
            
            $this->info("\n✓ Successfully deleted {$deletedCount} MRF(s) and all related data.");
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error deleting MRFs: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Delete files associated with MRF
     */
    private function deleteMRFFiles(MRF $mrf)
    {
        $files = [
            'pfi_url' => 'PFI',
            'unsigned_po_url' => 'Unsigned PO',
            'signed_po_url' => 'Signed PO',
            'grn_url' => 'GRN',
        ];

        $disk = config('filesystems.documents_disk', 'public');

        foreach ($files as $urlField => $fileType) {
            if ($mrf->$urlField) {
                try {
                    $url = $mrf->$urlField;
                    $path = $this->extractPathFromUrl($url, $disk);
                    
                    if ($path && Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        $this->line("  - Deleted {$fileType} file");
                    }
                } catch (\Exception $e) {
                    // Log but don't fail
                    $this->warn("  - Warning: Could not delete {$fileType} file: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Extract file path from URL
     */
    private function extractPathFromUrl(string $url, string $disk): ?string
    {
        // Try multiple extraction methods
        $possiblePaths = [
            parse_url($url, PHP_URL_PATH),
            ltrim(parse_url($url, PHP_URL_PATH), '/storage/'),
            basename(parse_url($url, PHP_URL_PATH)),
        ];

        $baseUrl = Storage::disk($disk)->url('');
        if ($baseUrl) {
            $possiblePaths[] = str_replace($baseUrl, '', $url);
        }

        foreach ($possiblePaths as $path) {
            if (empty($path)) continue;
            
            $path = ltrim(str_replace('/storage/', '', $path), '/');
            if (Storage::disk($disk)->exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
