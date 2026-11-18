<?php

namespace App\Services;

use App\Models\QuickBooksVendorCreationRequest;
use App\Models\Vendor;
use App\Models\VendorQuickBooksMapping;
use App\Support\PurchaseOrderCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickBooksSupplierService
{
    public function __construct(
        private QuickBooksPullService $pull,
        private QuickBooksService $quickBooks,
        private QuickBooksTokenService $tokens,
        private SupplierResolutionService $resolution,
        private ScmAuditService $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconciliationPreview(): array
    {
        $realmId = $this->tokens->realmId();
        $preview = $this->pull->pullVendors();
        $mappings = VendorQuickBooksMapping::query()
            ->where('realm_id', $realmId)
            ->get()
            ->keyBy(fn (VendorQuickBooksMapping $mapping) => $mapping->quickbooks_vendor_id.'|'.$mapping->currency);

        foreach (['matched', 'unmatched'] as $section) {
            foreach ($preview[$section] as &$row) {
                $key = (string) ($row['quickbooks_vendor_id'] ?? '').'|'.strtoupper((string) ($row['currency'] ?? ''));
                $mapping = $mappings->get($key);
                $row['realm_id'] = $realmId;
                $row['mapping_status'] = $mapping?->status;
                $row['mapping_id'] = $mapping?->id;
            }
            unset($row);
        }

        return [
            'realm_id' => $realmId,
            'supported_currencies' => PurchaseOrderCurrency::SUPPORTED,
            ...$preview,
        ];
    }

    public function approveMapping(
        Vendor $vendor,
        string $quickbooksVendorId,
        string $currency,
        string $matchMethod,
        int $approvedBy,
    ): VendorQuickBooksMapping {
        $currency = $this->validatedCurrency($currency);
        $realmId = $this->tokens->realmId();
        $quickbooksVendorId = trim($quickbooksVendorId);
        if ($quickbooksVendorId === '') {
            throw ValidationException::withMessages(['quickbooks_vendor_id' => 'A QuickBooks vendor ID is required.']);
        }

        $quickbooksVendor = $this->quickBooks->readVendor($quickbooksVendorId);
        if ($quickbooksVendor === null) {
            throw ValidationException::withMessages(['quickbooks_vendor_id' => 'The QuickBooks vendor could not be verified.']);
        }
        if ($quickbooksVendor['currency'] !== $currency) {
            throw ValidationException::withMessages(['currency' => 'The QuickBooks vendor currency does not match the requested mapping currency.']);
        }
        $identity = $this->resolution->resolve($quickbooksVendor['DisplayName'], $quickbooksVendor['email'] ?? null);
        if ($identity['status'] === 'review_required' || ($identity['status'] === 'matched' && (int) $identity['vendor']->id !== (int) $vendor->id)) {
            throw ValidationException::withMessages(['vendor_id' => 'The QuickBooks supplier identity conflicts with the selected SCM supplier.']);
        }
        if ($identity['status'] !== 'matched' && $matchMethod !== 'manual') {
            throw ValidationException::withMessages(['match_method' => 'A non-manual mapping requires an unambiguous supplier match.']);
        }

        $mapping = DB::transaction(function () use ($vendor, $quickbooksVendorId, $currency, $matchMethod, $approvedBy, $realmId) {
            $existingVendorMapping = VendorQuickBooksMapping::query()
                ->where('realm_id', $realmId)
                ->where('quickbooks_vendor_id', $quickbooksVendorId)
                ->lockForUpdate()
                ->first();
            if ($existingVendorMapping && (int) $existingVendorMapping->vendor_id !== (int) $vendor->id) {
                throw ValidationException::withMessages(['quickbooks_vendor_id' => 'This QuickBooks vendor is already mapped to another SCM supplier.']);
            }

            $mapping = VendorQuickBooksMapping::query()
                ->where('vendor_id', $vendor->id)
                ->where('realm_id', $realmId)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();
            if ($mapping && (string) $mapping->quickbooks_vendor_id !== $quickbooksVendorId) {
                throw ValidationException::withMessages(['currency' => 'A different mapping already exists for this supplier and currency.']);
            }

            $mapping ??= new VendorQuickBooksMapping([
                'vendor_id' => $vendor->id,
                'realm_id' => $realmId,
                'currency' => $currency,
                'quickbooks_vendor_id' => $quickbooksVendorId,
            ]);
            $mapping->status = VendorQuickBooksMapping::STATUS_APPROVED;
            $mapping->match_method = $matchMethod;
            $mapping->approved_by = $approvedBy;
            $mapping->approved_at = now();
            $mapping->save();

            if ($currency === PurchaseOrderCurrency::DEFAULT && blank($vendor->quickbooks_vendor_id)) {
                $vendor->quickbooks_vendor_id = $quickbooksVendorId;
                $vendor->save();
            }

            return $mapping->fresh();
        });

        $this->audit->record('quickbooks_supplier_mapping_approved', 'VendorQuickBooksMapping', $mapping->id, auth()->user(), 'Approved QuickBooks supplier mapping.', [
            'vendor_id' => $vendor->id,
            'realm_id' => $realmId,
            'currency' => $currency,
            'quickbooks_vendor_id' => $quickbooksVendorId,
            'match_method' => $matchMethod,
        ]);

        return $mapping;
    }

    public function importVendor(string $quickbooksVendorId, string $currency, int $actorId): array
    {
        $currency = $this->validatedCurrency($currency);
        $realmId = $this->tokens->realmId();
        $qb = $this->quickBooks->readVendor(trim($quickbooksVendorId));
        if ($qb === null) {
            throw ValidationException::withMessages(['quickbooks_vendor_id' => 'The QuickBooks vendor could not be verified.']);
        }
        if ($qb['currency'] !== $currency) {
            throw ValidationException::withMessages(['currency' => 'The QuickBooks vendor currency does not match the requested import currency.']);
        }
        if (VendorQuickBooksMapping::query()->where('realm_id', $realmId)->where('quickbooks_vendor_id', $quickbooksVendorId)->exists()) {
            throw ValidationException::withMessages(['quickbooks_vendor_id' => 'This QuickBooks vendor already has a mapping.']);
        }

        $name = trim($qb['DisplayName']);
        if ($name === '') {
            throw ValidationException::withMessages(['quickbooks_vendor_id' => 'The QuickBooks vendor has no usable name.']);
        }
        $email = trim((string) ($qb['email'] ?? ''));
        if ($email === '') {
            $email = 'quickbooks-'.$quickbooksVendorId.'@import.invalid';
        }

        $resolution = $this->resolution->resolveOrCreate($name, $email, function (string $resolvedName, string $resolvedEmail): Vendor {
            return Vendor::create([
                'vendor_id' => Vendor::generateVendorId(),
                'name' => $resolvedName,
                'category' => 'General',
                'status' => 'Pending',
                'email' => $resolvedEmail,
                'profile_completed' => false,
                'onboarding_source' => 'quickbooks_import',
            ]);
        });
        if ($resolution['status'] === 'review_required') {
            throw ValidationException::withMessages(['supplier' => $resolution['message'] ?? 'The imported supplier requires identity review.']);
        }

        $vendor = $resolution['vendor'];
        $mapping = $this->approvePendingMapping($vendor, $quickbooksVendorId, $currency, 'quickbooks_import', $actorId, $realmId);

        return ['vendor' => $vendor->fresh(), 'mapping' => $mapping];
    }

    public function prepareCreation(Vendor $vendor, string $currency, int $requestedBy): QuickBooksVendorCreationRequest
    {
        $currency = $this->validatedCurrency($currency);
        $realmId = $this->tokens->realmId();
        if ($vendor->status !== 'Active' || (isset($vendor->profile_completed) && ! $vendor->profile_completed)) {
            throw ValidationException::withMessages(['vendor' => 'The supplier must be approved and have completed required documentation.']);
        }
        if (VendorQuickBooksMapping::query()->where('vendor_id', $vendor->id)->where('realm_id', $realmId)->where('currency', $currency)->where('status', VendorQuickBooksMapping::STATUS_APPROVED)->exists()) {
            throw ValidationException::withMessages(['currency' => 'An approved QuickBooks mapping already exists for this supplier and currency.']);
        }
        $existingRequest = QuickBooksVendorCreationRequest::query()
            ->where('vendor_id', $vendor->id)
            ->where('realm_id', $realmId)
            ->where('currency', $currency)
            ->first();
        if ($existingRequest) {
            return $existingRequest;
        }

        $existingQuickBooksVendorId = $this->quickBooks->findVendorByName((string) $vendor->name);
        if ($existingQuickBooksVendorId !== null) {
            throw ValidationException::withMessages(['vendor' => 'A QuickBooks supplier with this name already exists. Reconcile it before requesting creation.']);
        }

        return QuickBooksVendorCreationRequest::query()->create([
            'vendor_id' => $vendor->id,
            'realm_id' => $realmId,
            'currency' => $currency,
            'status' => QuickBooksVendorCreationRequest::STATUS_PENDING,
            'requested_by' => $requestedBy,
        ]);
    }

    public function approveCreation(QuickBooksVendorCreationRequest $request, int $approvedBy, ?string $notes = null): QuickBooksVendorCreationRequest
    {
        if ($request->status !== QuickBooksVendorCreationRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => 'Only pending QuickBooks vendor creation requests can be approved.']);
        }
        $vendor = $request->vendor;
        if (! $vendor || $vendor->status !== 'Active' || (isset($vendor->profile_completed) && ! $vendor->profile_completed)) {
            throw ValidationException::withMessages(['vendor' => 'The supplier is no longer approved for QuickBooks vendor creation.']);
        }

        $request->update([
            'status' => QuickBooksVendorCreationRequest::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'decision_notes' => $notes,
        ]);
        $this->audit->record('quickbooks_vendor_creation_approved', 'QuickBooksVendorCreationRequest', $request->id, auth()->user(), 'Approved QuickBooks vendor preparation request.');

        return $request->fresh();
    }

    public function decideMapping(VendorQuickBooksMapping $mapping, string $status, int $actorId): VendorQuickBooksMapping
    {
        if (! in_array($status, [VendorQuickBooksMapping::STATUS_REJECTED, VendorQuickBooksMapping::STATUS_DEFERRED], true)) {
            throw ValidationException::withMessages(['status' => 'The mapping decision must be rejected or deferred.']);
        }
        if ((string) $mapping->realm_id !== (string) $this->tokens->realmId()) {
            throw ValidationException::withMessages(['mapping' => 'The mapping belongs to a different QuickBooks company.']);
        }

        $mapping->update([
            'status' => $status,
            'approved_by' => $actorId,
            'approved_at' => now(),
        ]);
        $this->audit->record('quickbooks_supplier_mapping_'.$status, 'VendorQuickBooksMapping', $mapping->id, auth()->user(), 'Recorded QuickBooks supplier mapping decision.');

        return $mapping->fresh();
    }

    public function mappingsFor(Vendor $vendor): array
    {
        $realmId = $this->tokens->realmId();

        return [
            'realm_id' => $realmId,
            'mappings' => $vendor->quickbooksMappings()->where('realm_id', $realmId)->get(),
            'creation_requests' => $vendor->quickbooksCreationRequests()->where('realm_id', $realmId)->get(),
        ];
    }

    public function approvedMapping(Vendor $vendor, string $currency): ?VendorQuickBooksMapping
    {
        return $vendor->quickbooksMappings()
            ->where('realm_id', $this->tokens->realmId())
            ->where('currency', strtoupper(trim($currency)))
            ->where('status', VendorQuickBooksMapping::STATUS_APPROVED)
            ->first();
    }

    private function approvePendingMapping(Vendor $vendor, string $quickbooksVendorId, string $currency, string $method, int $actorId, string $realmId): VendorQuickBooksMapping
    {
        $mapping = VendorQuickBooksMapping::create([
            'vendor_id' => $vendor->id,
            'realm_id' => $realmId,
            'currency' => $currency,
            'quickbooks_vendor_id' => $quickbooksVendorId,
            'status' => VendorQuickBooksMapping::STATUS_PENDING,
            'match_method' => $method,
        ]);
        $mapping->approved_by = null;
        $mapping->approved_at = null;
        $mapping->save();

        return $mapping;
    }

    private function validatedCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (! in_array($currency, PurchaseOrderCurrency::SUPPORTED, true)) {
            throw ValidationException::withMessages(['currency' => 'The currency is unsupported or unidentified.']);
        }

        return $currency;
    }
}
