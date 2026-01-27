<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FixMissingMRFColumns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mrf:fix-columns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and add missing columns to m_r_f_s table if migrations were marked as run but columns are missing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for missing columns in m_r_f_s table...');
        
        $columnsToAdd = [];
        $columnsFixed = [];
        
        // Check contract_type
        if (!Schema::hasColumn('m_r_f_s', 'contract_type')) {
            $columnsToAdd[] = 'contract_type';
            try {
                DB::statement("ALTER TABLE m_r_f_s ADD COLUMN contract_type VARCHAR(50) NULL COMMENT 'Contract type: emerald, oando, dangote, heritage'");
                $columnsFixed[] = 'contract_type';
                $this->info('✓ Added contract_type column');
            } catch (\Exception $e) {
                $this->error('✗ Failed to add contract_type: ' . $e->getMessage());
            }
        } else {
            $this->info('✓ contract_type column exists');
        }
        
        // Check ship_to_address
        if (!Schema::hasColumn('m_r_f_s', 'ship_to_address')) {
            $columnsToAdd[] = 'ship_to_address';
            try {
                DB::statement("ALTER TABLE m_r_f_s ADD COLUMN ship_to_address TEXT NULL");
                $columnsFixed[] = 'ship_to_address';
                $this->info('✓ Added ship_to_address column');
            } catch (\Exception $e) {
                $this->error('✗ Failed to add ship_to_address: ' . $e->getMessage());
            }
        } else {
            $this->info('✓ ship_to_address column exists');
        }
        
        // Check tax_rate
        if (!Schema::hasColumn('m_r_f_s', 'tax_rate')) {
            $columnsToAdd[] = 'tax_rate';
            try {
                DB::statement("ALTER TABLE m_r_f_s ADD COLUMN tax_rate DECIMAL(5,2) DEFAULT 0");
                $columnsFixed[] = 'tax_rate';
                $this->info('✓ Added tax_rate column');
            } catch (\Exception $e) {
                $this->error('✗ Failed to add tax_rate: ' . $e->getMessage());
            }
        } else {
            $this->info('✓ tax_rate column exists');
        }
        
        // Check tax_amount
        if (!Schema::hasColumn('m_r_f_s', 'tax_amount')) {
            $columnsToAdd[] = 'tax_amount';
            try {
                DB::statement("ALTER TABLE m_r_f_s ADD COLUMN tax_amount DECIMAL(15,2) DEFAULT 0");
                $columnsFixed[] = 'tax_amount';
                $this->info('✓ Added tax_amount column');
            } catch (\Exception $e) {
                $this->error('✗ Failed to add tax_amount: ' . $e->getMessage());
            }
        } else {
            $this->info('✓ tax_amount column exists');
        }
        
        // Check po_special_terms
        if (!Schema::hasColumn('m_r_f_s', 'po_special_terms')) {
            $columnsToAdd[] = 'po_special_terms';
            try {
                DB::statement("ALTER TABLE m_r_f_s ADD COLUMN po_special_terms TEXT NULL");
                $columnsFixed[] = 'po_special_terms';
                $this->info('✓ Added po_special_terms column');
            } catch (\Exception $e) {
                $this->error('✗ Failed to add po_special_terms: ' . $e->getMessage());
            }
        } else {
            $this->info('✓ po_special_terms column exists');
        }
        
        // Check invoice_submission_email
        if (!Schema::hasColumn('m_r_f_s', 'invoice_submission_email')) {
            $columnsToAdd[] = 'invoice_submission_email';
            try {
                DB::statement("ALTER TABLE m_r_f_s ADD COLUMN invoice_submission_email VARCHAR(255) NULL");
                $columnsFixed[] = 'invoice_submission_email';
                $this->info('✓ Added invoice_submission_email column');
            } catch (\Exception $e) {
                $this->error('✗ Failed to add invoice_submission_email: ' . $e->getMessage());
            }
        } else {
            $this->info('✓ invoice_submission_email column exists');
        }
        
        // Check invoice_submission_cc
        if (!Schema::hasColumn('m_r_f_s', 'invoice_submission_cc')) {
            $columnsToAdd[] = 'invoice_submission_cc';
            try {
                DB::statement("ALTER TABLE m_r_f_s ADD COLUMN invoice_submission_cc VARCHAR(255) NULL");
                $columnsFixed[] = 'invoice_submission_cc';
                $this->info('✓ Added invoice_submission_cc column');
            } catch (\Exception $e) {
                $this->error('✗ Failed to add invoice_submission_cc: ' . $e->getMessage());
            }
        } else {
            $this->info('✓ invoice_submission_cc column exists');
        }
        
        if (empty($columnsFixed)) {
            $this->info('All columns already exist. No changes needed.');
        } else {
            $this->info('Successfully added ' . count($columnsFixed) . ' column(s).');
        }
        
        return 0;
    }
}
