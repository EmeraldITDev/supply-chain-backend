<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\QuotationItem;
use App\Models\Quotation;

echo "=== Quotation Items Database Check ===\n\n";

$total = QuotationItem::count();
echo "Total quotation items: {$total}\n\n";

if ($total > 0) {
    $recent = QuotationItem::with('quotation')
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();
    
    echo "Recent items (last 10):\n";
    echo str_repeat("-", 80) . "\n";
    foreach ($recent as $item) {
        $quotationStringId = $item->quotation ? $item->quotation->quotation_id : 'N/A';
        echo sprintf(
            "ID: %-5s | Quotation ID (auto): %-5s | Quotation ID (string): %-15s | Item: %-30s | Qty: %-5s | Price: %-10s\n",
            $item->id,
            $item->quotation_id,
            $quotationStringId,
            substr($item->item_name, 0, 30),
            $item->quantity,
            number_format($item->unit_price, 2)
        );
    }
    echo str_repeat("-", 80) . "\n";
} else {
    echo "⚠️  No quotation items found in database!\n";
}

// Check quotations without items
$quotationsWithoutItems = Quotation::whereDoesntHave('items')->count();
$quotationsWithItems = Quotation::whereHas('items')->count();
$totalQuotations = Quotation::count();

echo "\n=== Summary ===\n";
echo "Total quotations: {$totalQuotations}\n";
echo "Quotations with items: {$quotationsWithItems}\n";
echo "Quotations without items: {$quotationsWithoutItems}\n";

if ($quotationsWithoutItems > 0) {
    echo "\n⚠️  Warning: {$quotationsWithoutItems} quotation(s) have no items!\n";
    echo "These quotations may cause PO generation to fail.\n";
}
