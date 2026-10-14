<?php

namespace App\Console\Commands;

use App\Exceptions\QuickBooksException;
use App\Services\QuickBooksService;
use Illuminate\Console\Command;

class QuickBooksInspect extends Command
{
    protected $signature = 'quickbooks:inspect';

    protected $description = 'Print a read-only summary of QuickBooks company, accounts, items, vendors, and purchase orders';

    public function handle(QuickBooksService $quickBooks): int
    {
        try {
            $report = $quickBooks->inspectionReport();
        } catch (QuickBooksException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $docNumbers = $report['doc_numbers'] ?? [];
        if ($docNumbers === []) {
            $this->line('No PurchaseOrder DocNumber values were returned.');
        } else {
            $lengths = array_map(fn ($value) => (string) mb_strlen((string) $value), $docNumbers);
            $this->line('DocNumber samples: '.implode(', ', $docNumbers));
            $this->line('DocNumber lengths: '.implode(', ', $lengths));
        }
        $this->line('Use an Item Id as QUICKBOOKS_DEFAULT_ITEM_ID, or an Expense account Id as QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID.');
        $this->line('If more than one accounts-payable account is listed, set QUICKBOOKS_AP_ACCOUNT_ID as well.');

        return self::SUCCESS;
    }
}
