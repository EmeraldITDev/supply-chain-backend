# Finance AP — `POST /api/v1/integrations/scm/vendors`

**Status:** **Implemented** (Jun 2026)

Reference implementation notes below (for onboarding / audits). SCM calls this endpoint on vendor create/update and via `php artisan finance-ap:sync-vendors --force`.

**If sync still fails:** verify FA deploy is live, `X-Api-Key` matches SCM `FINANCE_AP_API_KEY`, and `php artisan route:list --path=integrations/scm/vendors` on FA shows the route.

---

## 1. Route (`routes/api.php`)

Register **inside** your existing `v1` + SCM integration middleware group (same auth as `POST .../packages`):

```php
Route::prefix('v1')->group(function () {
    Route::prefix('integrations/scm')
        ->middleware('scm.integration') // or whatever validates X-Api-Key from SCM
        ->group(function () {
            Route::post('/vendors', [ScmVendorIntegrationController::class, 'upsert']);
            // existing: packages, delta, ...
        });
});
```

**Verify after deploy:**

```bash
php artisan route:list --path=integrations/scm/vendors
```

Expected: `POST api/v1/integrations/scm/vendors`

---

## 2. Controller

`app/Http/Controllers/Api/V1/Integrations/Scm/ScmVendorIntegrationController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1\Integrations\Scm;

use App\Http\Controllers\Controller;
use App\Services\Scm\ScmVendorSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScmVendorIntegrationController extends Controller
{
    public function __construct(
        private ScmVendorSyncService $scmVendorSyncService,
    ) {
    }

    public function upsert(Request $request): JsonResponse
    {
        $snapshot = $request->input('vendor');

        if (! is_array($snapshot) || $snapshot === []) {
            throw ValidationException::withMessages([
                'vendor' => ['vendor snapshot is required'],
            ]);
        }

        $vendor = $this->scmVendorSyncService->upsertFromSnapshot($snapshot);

        return response()->json([
            'success' => true,
            'data' => [
                'financeApVendorId' => $vendor->id,
                'finance_ap_vendor_id' => $vendor->id,
                'scmVendorId' => (int) ($snapshot['scmVendorId'] ?? $snapshot['scm_vendor_id']),
                'scm_vendor_id' => (int) ($snapshot['scmVendorId'] ?? $snapshot['scm_vendor_id']),
                'vendorCode' => $snapshot['vendorCode'] ?? $snapshot['vendor_code'] ?? null,
                'vendor_code' => $snapshot['vendorCode'] ?? $snapshot['vendor_code'] ?? null,
            ],
        ]);
    }
}
```

---

## 3. Service

`app/Services/Scm/ScmVendorSyncService.php`

```php
<?php

namespace App\Services\Scm;

use App\Models\Vendor;
use App\Models\VendorScmMapping;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ScmVendorSyncService
{
    public function upsertFromSnapshot(array $snapshot): Vendor
    {
        $scmVendorId = (int) ($snapshot['scmVendorId'] ?? $snapshot['scm_vendor_id'] ?? 0);

        if ($scmVendorId <= 0) {
            throw ValidationException::withMessages([
                'vendor.scm_vendor_id' => ['scm_vendor_id is required'],
            ]);
        }

        $mapping = VendorScmMapping::query()
            ->where('scm_vendor_id', $scmVendorId)
            ->first();

        $attributes = $this->mapSnapshotToVendorAttributes($snapshot);

        if ($mapping) {
            $vendor = $mapping->vendor;
            $vendor->update($attributes);
            $mapping->update([
                'vendor_code' => $attributes['vendor_code'] ?? $mapping->vendor_code,
                'last_synced_at' => $snapshot['snapshotAt'] ?? $snapshot['snapshot_at'] ?? now(),
            ]);

            return $vendor->fresh();
        }

        $vendor = Vendor::query()->create($attributes);

        VendorScmMapping::query()->create([
            'scm_vendor_id' => $scmVendorId,
            'finance_ap_vendor_id' => $vendor->id,
            'vendor_code' => $attributes['vendor_code'] ?? null,
            'last_synced_at' => $snapshot['snapshotAt'] ?? $snapshot['snapshot_at'] ?? now(),
        ]);

        return $vendor;
    }

  /**
     * @return array<string, mixed>
     */
    private function mapSnapshotToVendorAttributes(array $s): array
    {
        return [
            'source' => 'scm',
            'is_scm_master' => true,
            'scm_vendor_id' => (int) ($s['scmVendorId'] ?? $s['scm_vendor_id']),
            'vendor_code' => $s['vendorCode'] ?? $s['vendor_code'] ?? null,
            'name' => $s['name'] ?? 'Unknown',
            'status' => $s['status'] ?? 'Active',
            'category' => $s['category'] ?? null,
            'category_other' => $s['categoryOther'] ?? $s['category_other'] ?? null,
            'email' => $s['email'] ?? null,
            'phone' => $s['phone'] ?? null,
            'alternate_phone' => $s['alternatePhone'] ?? $s['alternate_phone'] ?? null,
            'tax_id' => $s['taxId'] ?? $s['tax_id'] ?? null,
            'website' => $s['website'] ?? null,
            'address' => $s['addressLine1'] ?? $s['address_line1'] ?? $s['address'] ?? null,
            'city' => $s['city'] ?? null,
            'state' => $s['state'] ?? null,
            'postal_code' => $s['postalCode'] ?? $s['postal_code'] ?? null,
            'country_code' => $s['countryCode'] ?? $s['country_code'] ?? null,
            'contact_person' => $s['contactPerson'] ?? $s['contact_person'] ?? null,
            'contact_person_title' => $s['contactPersonTitle'] ?? $s['contact_person_title'] ?? null,
            'contact_person_email' => $s['contactPersonEmail'] ?? $s['contact_person_email'] ?? null,
            'contact_person_phone' => $s['contactPersonPhone'] ?? $s['contact_person_phone'] ?? null,
        ];
    }
}
```

Adjust column names to match your existing `vendors` table.

---

## 4. Migration (if not already applied)

```php
Schema::create('vendor_scm_mappings', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('scm_vendor_id')->unique();
    $table->foreignId('finance_ap_vendor_id')->constrained('vendors')->cascadeOnDelete();
    $table->string('vendor_code', 32)->nullable()->index();
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamps();
});

Schema::table('vendors', function (Blueprint $table) {
    if (! Schema::hasColumn('vendors', 'source')) {
        $table->string('source', 16)->default('local')->after('id');
    }
    if (! Schema::hasColumn('vendors', 'scm_vendor_id')) {
        $table->unsignedBigInteger('scm_vendor_id')->nullable()->unique()->after('source');
    }
    if (! Schema::hasColumn('vendors', 'vendor_code')) {
        $table->string('vendor_code', 32)->nullable()->index()->after('scm_vendor_id');
    }
    if (! Schema::hasColumn('vendors', 'is_scm_master')) {
        $table->boolean('is_scm_master')->default(false)->after('vendor_code');
    }
});
```

---

## 5. Auth middleware

SCM sends:

```
X-Api-Key: <same value as SCM FINANCE_AP_API_KEY>
Idempotency-Key: vendor:76:v1719234567
```

Finance AP must validate `X-Api-Key` against `SCM_INTEGRATION_API_KEY` (or your existing package ingest key). **Use the same middleware as package ingest.**

---

## 6. Fix `GET /api/v1/vendors` empty list

Your list endpoint likely filters `source = 'scm'` or joins `vendor_scm_mappings`. After this route works:

1. SCM runs `php artisan finance-ap:sync-vendors --force`
2. Rows appear in `vendors` + `vendor_scm_mappings`
3. `GET /api/v1/vendors` returns data at `response.data.data`

If list is still empty, check the query matches how ingest stores rows (`source`, `is_scm_master`, or mapping join).

---

## 7. Smoke test (from SCM server or curl)

```bash
curl -sS -X POST "https://financeap-backend.onrender.com/api/v1/integrations/scm/vendors" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_SCM_INTEGRATION_KEY" \
  -H "Idempotency-Key: test-vendor-76" \
  -d '{
    "vendor": {
      "source": "scm",
      "scmVendorId": 76,
      "vendorCode": "V023",
      "name": "Test Vendor",
      "status": "Active",
      "email": "test@example.com",
      "snapshotAt": "2026-06-24T12:00:00Z"
    }
  }'
```

Expect `200` and `success: true`, not `404`.

Then on SCM:

```bash
php artisan finance-ap:sync-vendors --force
```

---

## 8. Related docs

- Full contract: `supply-chain-backend/docs/FINANCE_AP_VENDOR_SYNC_PATTERN_A.md`
- SCM logs failed pushes in `finance_sync_events` where `event_type = vendor_sync`
