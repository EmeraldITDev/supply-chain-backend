# How to Check quotation_items Records in Database

## Method 1: Direct SQL Query (PostgreSQL/MySQL)

### PostgreSQL (if using PostgreSQL):
```sql
-- Check total count
SELECT COUNT(*) FROM quotation_items;

-- Check all records
SELECT * FROM quotation_items ORDER BY created_at DESC;

-- Check items for a specific quotation (by auto-increment ID)
SELECT * FROM quotation_items WHERE quotation_id = 1;

-- Check items for a specific quotation (by string quotation_id)
SELECT qi.* 
FROM quotation_items qi
JOIN quotations q ON qi.quotation_id = q.id
WHERE q.quotation_id = 'QUO-2026-001';

-- Check items with quotation details
SELECT 
    qi.id,
    qi.quotation_id,
    q.quotation_id as quotation_string_id,
    qi.item_name,
    qi.quantity,
    qi.unit_price,
    qi.total_price,
    qi.created_at
FROM quotation_items qi
JOIN quotations q ON qi.quotation_id = q.id
ORDER BY qi.created_at DESC
LIMIT 20;
```

### MySQL (if using MySQL):
```sql
-- Same queries as above work for MySQL too
SELECT COUNT(*) FROM quotation_items;
SELECT * FROM quotation_items ORDER BY created_at DESC;
```

## Method 2: Laravel Tinker (Command Line)

```bash
# Enter tinker
php artisan tinker

# Then run these commands:
```

```php
// Check total count
\App\Models\QuotationItem::count();

// Get all quotation items
\App\Models\QuotationItem::all();

// Get recent items with quotation details
\App\Models\QuotationItem::with('quotation')->orderBy('created_at', 'desc')->limit(10)->get();

// Check items for a specific quotation (by auto-increment ID)
\App\Models\QuotationItem::where('quotation_id', 1)->get();

// Check items for a specific quotation (by string quotation_id)
$quotation = \App\Models\Quotation::where('quotation_id', 'QUO-2026-001')->first();
if ($quotation) {
    \App\Models\QuotationItem::where('quotation_id', $quotation->id)->get();
}

// Check if a quotation has items
$quotation = \App\Models\Quotation::where('quotation_id', 'QUO-2026-001')->first();
if ($quotation) {
    $hasItems = \App\Models\QuotationItem::where('quotation_id', $quotation->id)->exists();
    echo $hasItems ? "Has items" : "No items";
}
```

## Method 3: Create a Simple Artisan Command

Create a command to check quotation items:

```bash
php artisan make:command CheckQuotationItems
```

Then edit `app/Console/Commands/CheckQuotationItems.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\QuotationItem;
use App\Models\Quotation;

class CheckQuotationItems extends Command
{
    protected $signature = 'quotation:check-items {quotation_id?}';
    protected $description = 'Check quotation items in database';

    public function handle()
    {
        $quotationId = $this->argument('quotation_id');
        
        if ($quotationId) {
            // Check specific quotation
            $quotation = Quotation::where('quotation_id', $quotationId)
                ->orWhere('id', $quotationId)
                ->first();
            
            if (!$quotation) {
                $this->error("Quotation not found: {$quotationId}");
                return;
            }
            
            $items = QuotationItem::where('quotation_id', $quotation->id)->get();
            
            $this->info("Quotation: {$quotation->quotation_id} (ID: {$quotation->id})");
            $this->info("Items count: " . $items->count());
            
            if ($items->count() > 0) {
                $this->table(
                    ['ID', 'Item Name', 'Quantity', 'Unit Price', 'Total Price'],
                    $items->map(function($item) {
                        return [
                            $item->id,
                            $item->item_name,
                            $item->quantity,
                            $item->unit_price,
                            $item->total_price,
                        ];
                    })->toArray()
                );
            } else {
                $this->warn("No items found for this quotation!");
            }
        } else {
            // Show summary
            $total = QuotationItem::count();
            $quotationsWithItems = Quotation::whereHas('items')->count();
            $quotationsWithoutItems = Quotation::whereDoesntHave('items')->count();
            
            $this->info("Total quotation items: {$total}");
            $this->info("Quotations with items: {$quotationsWithItems}");
            $this->info("Quotations without items: {$quotationsWithoutItems}");
        }
    }
}
```

Then run:
```bash
# Check all
php artisan quotation:check-items

# Check specific quotation
php artisan quotation:check-items QUO-2026-001
```

## Method 4: Using Database GUI Tool

If you have access to a database GUI (pgAdmin, phpMyAdmin, TablePlus, etc.):

1. Connect to your database
2. Navigate to `quotation_items` table
3. Browse the table or run queries

## Method 5: Check via API Endpoint (if you create one)

You could add a debug endpoint in `routes/api.php`:

```php
Route::get('/debug/quotation-items/{quotationId}', function($quotationId) {
    $quotation = \App\Models\Quotation::where('quotation_id', $quotationId)
        ->orWhere('id', $quotationId)
        ->first();
    
    if (!$quotation) {
        return response()->json(['error' => 'Quotation not found'], 404);
    }
    
    $items = \App\Models\QuotationItem::where('quotation_id', $quotation->id)->get();
    
    return response()->json([
        'quotation_id' => $quotation->quotation_id,
        'quotation_auto_id' => $quotation->id,
        'items_count' => $items->count(),
        'items' => $items
    ]);
});
```

Then call: `GET /api/debug/quotation-items/QUO-2026-001`

## Method 6: Check Logs

The PO generation code now logs quotation items. Check Laravel logs:

```bash
tail -f storage/logs/laravel.log | grep "PO Generation: Checking quotation items"
```

## Quick Check Script

Save this as `check_items.php` in project root:

```php
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\QuotationItem;
use App\Models\Quotation;

echo "=== Quotation Items Check ===\n\n";

$total = QuotationItem::count();
echo "Total quotation items: {$total}\n\n";

if ($total > 0) {
    $recent = QuotationItem::with('quotation')
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get();
    
    echo "Recent items:\n";
    foreach ($recent as $item) {
        echo "  - ID: {$item->id}, Quotation ID: {$item->quotation_id}, Item: {$item->item_name}\n";
    }
} else {
    echo "No quotation items found in database!\n";
}

// Check quotations without items
$quotationsWithoutItems = Quotation::whereDoesntHave('items')->count();
echo "\nQuotations without items: {$quotationsWithoutItems}\n";
```

Run: `php check_items.php`
