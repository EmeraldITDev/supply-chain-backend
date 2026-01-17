<?php

namespace App\Console\Commands;

use App\Models\MRF;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteMRF extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mrf:delete {mrf_id : The MRF ID to delete (e.g., MRF-2026-001)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete an MRF by its MRF ID (e.g., MRF-2026-001)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mrfId = $this->argument('mrf_id');
        
        $this->info("Searching for MRF: {$mrfId}...");
        
        $mrf = MRF::where('mrf_id', $mrfId)->first();
        
        if (!$mrf) {
            $this->error("MRF '{$mrfId}' not found in the database.");
            return 1;
        }
        
        $this->info("Found MRF:");
        $this->line("  ID: {$mrf->mrf_id}");
        $this->line("  Title: {$mrf->title}");
        $this->line("  Status: {$mrf->status}");
        $this->line("  Workflow State: {$mrf->workflow_state}");
        $this->line("  Created: {$mrf->created_at}");
        
        if (!$this->confirm('Are you sure you want to delete this MRF? This action cannot be undone.')) {
            $this->info('Deletion cancelled.');
            return 0;
        }
        
        try {
            DB::beginTransaction();
            
            // Delete related records first
            $this->info('Deleting related records...');
            
            // Delete RFQs related to this MRF
            $rfqCount = DB::table('r_f_q_s')->where('mrf_id', $mrf->id)->count();
            if ($rfqCount > 0) {
                DB::table('r_f_q_s')->where('mrf_id', $mrf->id)->delete();
                $this->line("  Deleted {$rfqCount} RFQ(s)");
            }
            
            // Delete the MRF
            $mrf->delete();
            
            DB::commit();
            
            $this->info("✓ MRF '{$mrfId}' has been successfully deleted.");
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Failed to delete MRF: " . $e->getMessage());
            return 1;
        }
    }
}
