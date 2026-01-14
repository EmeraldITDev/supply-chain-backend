# Clear Test PO Numbers from Database

If you have test PO numbers in the database that are blocking new PO generation, here are your options:

## Option 1: Clear ALL PO Numbers (Development Only!)

**⚠️ WARNING: This will remove ALL PO data from ALL MRFs. Only use in development!**

```bash
cd "/Users/asukuonukaba/Desktop/SCM Backend/supply-chain-backend"

# Open tinker (Laravel's interactive shell)
php artisan tinker

# Then run these commands in tinker:
App\Models\MRF::whereNotNull('po_number')->update([
    'po_number' => null,
    'unsigned_po_url' => null,
    'signed_po_url' => null,
    'po_generated_at' => null,
    'status' => 'procurement',
    'current_stage' => 'procurement'
]);

# Exit tinker
exit
```

## Option 2: Clear Specific PO Numbers

If you know the specific PO numbers causing issues:

```bash
php artisan tinker

# Replace 'PO-2026-001' with the actual PO number
App\Models\MRF::where('po_number', 'PO-2026-001')->update([
    'po_number' => null,
    'unsigned_po_url' => null,
    'signed_po_url' => null,
    'po_generated_at' => null,
]);

exit
```

## Option 3: View All PO Numbers

To see what PO numbers exist in the database:

```bash
php artisan tinker

# List all MRFs with PO numbers
App\Models\MRF::whereNotNull('po_number')->get(['mrf_id', 'po_number', 'status'])->toArray();

exit
```

## Option 4: Reset MRFs Back to Procurement Stage

If you want to keep the MRF but regenerate the PO:

```bash
php artisan tinker

# Reset specific MRF by ID
$mrf = App\Models\MRF::where('mrf_id', 'MRF-2026-001')->first();
$mrf->update([
    'status' => 'PO Rejected',
    'current_stage' => 'procurement',
    'rejection_reason' => 'Test regeneration'
]);

# Now you can regenerate PO for this MRF

exit
```

## Better Solution: The Code Now Handles This!

I've updated the backend code to:
- ✅ **Allow PO regeneration** for rejected POs
- ✅ **Reuse the same PO number** when regenerating
- ✅ **Delete old PO files** when regenerating
- ✅ **Accept MRFs with status "PO Rejected"**

So you shouldn't need to clear the database manually anymore!

## Production (Render):

For production, connect to the Render shell and run:

```bash
php artisan tinker

# Then run the same commands as above
```
