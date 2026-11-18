<?php

namespace App\Services;

use App\Exceptions\QuickBooksSupplierConflictException;
use App\Models\QuickBooksVendorCreationRequest;
use App\Models\QuickBooksSupplierExclusion;
use App\Models\Vendor;
use App\Models\VendorQuickBooksMapping;
use App\Support\PurchaseOrderCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickBooksSupplierService
{
    private const BULK_MAX = 50;

    private const PREVIEW_MAX = 5000;

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

    /**
     * Classify only the currently unmatched QuickBooks suppliers. The preview
     * is read-only; it never creates SCM records or mappings.
     *
     * @return array<string, mixed>
     */
    public function bulkClassificationPreview(int $page = 1, int $perPage = 25, ?string $search = null, string $filter = 'all'): array
    {
        $page = max(1, $page);
        $perPage = min(self::PREVIEW_MAX, max(1, $perPage));
        $realmId = $this->tokens->realmId();
        $preview = $this->reconciliationPreview();
        $mappings = VendorQuickBooksMapping::query()->where('realm_id', $realmId)->get()
            ->keyBy(fn (VendorQuickBooksMapping $mapping) => $mapping->quickbooks_vendor_id.'|'.$mapping->currency);
        $exclusions = QuickBooksSupplierExclusion::query()->where('realm_id', $realmId)->get()
            ->keyBy(fn (QuickBooksSupplierExclusion $exclusion) => $exclusion->quickbooks_vendor_id.'|'.$exclusion->currency);

        $rows = collect($preview['unmatched'])->map(function (array $row) use ($mappings, $exclusions, $realmId) {
            $vendorId = (string) ($row['id'] ?? '');
            $currency = strtoupper((string) ($row['currency'] ?? ''));
            $key = $vendorId.'|'.$currency;
            $mapping = $mappings->get($key);
            $exclusion = $exclusions->get($key);
            $classification = $this->classifyUnmatchedVendor($row, $mappings, $realmId);

            if ($mapping) {
                $classification = ['code' => 'already_linked', 'label' => 'Already linked', 'explanation' => 'A QuickBooks mapping already exists for this realm and currency.', 'eligible_import' => false];
            } elseif ($exclusion?->status === 'excluded') {
                $classification = ['code' => 'previously_excluded', 'label' => 'Previously excluded', 'explanation' => $exclusion->reason, 'eligible_import' => false];
            }

            return [
                ...$row,
                'realm_id' => $realmId,
                'classification' => $classification['code'],
                'classification_label' => $classification['label'],
                'classification_explanation' => $classification['explanation'],
                'eligible_import' => $classification['eligible_import'],
                'mapping_id' => $mapping?->id,
                'exclusion_id' => $exclusion?->id,
                'exclusion_status' => $exclusion?->status,
                'exclusion_reason' => $exclusion?->reason,
            ];
        });

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower(implode(' ', [
                (string) ($row['id'] ?? ''),
                (string) ($row['display_name'] ?? ''),
                (string) ($row['company_name'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['currency'] ?? ''),
            ])), $needle))->values();
        }
        if ($filter !== 'all') {
            $rows = $rows->where('classification', $filter)->values();
        }

        $total = $rows->count();
        $items = $rows->forPage($page, $perPage)->values()->all();

        return [
            'realm_id' => $realmId,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'items' => $items,
            'classifications' => [
                'business_import_candidate',
                'likely_individual',
                'possible_duplicate',
                'quickbooks_identity_overlap',
                'missing_email',
                'insufficient_evidence',
                'previously_excluded',
                'already_linked',
            ],
        ];
    }

    /**
     * @param list<array{quickbooks_vendor_id: string, currency: string, manual_override?: bool, justification?: string}> $records
     * @return array{results: list<array<string, mixed>>, processed: int}
     */
    public function bulkImport(array $records, int $actorId): array
    {
        if (count($records) > self::BULK_MAX) {
            throw ValidationException::withMessages(['records' => 'A maximum of '.self::BULK_MAX.' suppliers may be processed per batch.']);
        }

        $preview = $this->bulkClassificationPreview(1, self::PREVIEW_MAX);
        $available = collect($preview['items'])->keyBy(fn (array $row) => $row['id'].'|'.$row['currency']);
        $results = [];
        foreach ($records as $record) {
            $key = trim($record['quickbooks_vendor_id']).'|'.strtoupper(trim($record['currency']));
            $row = $available->get($key);
            if (! $row) {
                $results[] = ['quickbooks_vendor_id' => $record['quickbooks_vendor_id'], 'currency' => $record['currency'], 'result' => 'skipped', 'reason' => 'The supplier is not present in the current unmatched preview.'];
                continue;
            }
            if ($row['classification'] === 'already_linked') {
                $results[] = [...$this->bulkIdentity($row), 'result' => 'already_linked'];
                continue;
            }
            if ($row['classification'] === 'previously_excluded') {
                $results[] = [...$this->bulkIdentity($row), 'result' => 'excluded'];
                continue;
            }
            if (! $row['eligible_import']) {
                $results[] = [...$this->bulkIdentity($row), 'result' => 'skipped', 'reason' => $row['classification_explanation']];
                continue;
            }

            try {
                $imported = $this->importVendor((string) $row['id'], (string) $row['currency'], $actorId);
                $results[] = [...$this->bulkIdentity($row), 'result' => 'imported', 'vendor_id' => $imported['vendor']->id, 'mapping_id' => $imported['mapping']->id];
            } catch (\Throwable $exception) {
                $existing = VendorQuickBooksMapping::query()->where('realm_id', $preview['realm_id'])->where('quickbooks_vendor_id', $row['id'])->where('currency', $row['currency'])->first();
                $results[] = [...$this->bulkIdentity($row), 'result' => $existing ? 'already_linked' : 'failed', 'reason' => $existing ? 'The supplier was linked by a concurrent or repeated request.' : $exception->getMessage()];
            }
        }

        return ['results' => $results, 'processed' => count($results)];
    }

    /**
     * @param list<array{quickbooks_vendor_id: string, currency: string, reason: string}> $records
     * @return array{results: list<array<string, mixed>>, processed: int}
     */
    public function bulkExclude(array $records, int $actorId): array
    {
        if (count($records) > self::BULK_MAX) {
            throw ValidationException::withMessages(['records' => 'A maximum of '.self::BULK_MAX.' suppliers may be processed per batch.']);
        }
        $realmId = $this->tokens->realmId();
        $preview = $this->bulkClassificationPreview(1, self::PREVIEW_MAX);
        $available = collect($preview['items'])->keyBy(fn (array $row) => $row['id'].'|'.$row['currency']);
        $results = [];
        foreach ($records as $record) {
            $vendorId = trim($record['quickbooks_vendor_id']);
            $currency = $this->validatedCurrency($record['currency']);
            $row = $available->get($vendorId.'|'.$currency);
            if (! $row) {
                $results[] = ['quickbooks_vendor_id' => $vendorId, 'currency' => $currency, 'result' => 'skipped', 'reason' => 'The supplier is not present in the current unmatched preview.'];
                continue;
            }
            if ($row['classification'] === 'already_linked') {
                $results[] = ['quickbooks_vendor_id' => $vendorId, 'currency' => $currency, 'result' => 'conflict', 'reason' => 'A linked SCM mapping cannot be excluded from the unmatched workflow.'];
                continue;
            }
            $exclusion = QuickBooksSupplierExclusion::query()->updateOrCreate(
                ['realm_id' => $realmId, 'quickbooks_vendor_id' => $vendorId, 'currency' => $currency],
                ['reason' => trim($record['reason']), 'excluded_by' => $actorId, 'excluded_at' => now(), 'restored_by' => null, 'restored_at' => null, 'status' => 'excluded'],
            );
            $this->audit->record('quickbooks_supplier_excluded', 'QuickBooksSupplierExclusion', $exclusion->id, auth()->user(), 'Excluded QuickBooks supplier from SCM import review.', [
                'realm_id' => $realmId,
                'quickbooks_vendor_id' => $vendorId,
                'currency' => $currency,
                'reason' => trim($record['reason']),
            ]);
            $results[] = ['quickbooks_vendor_id' => $vendorId, 'currency' => $currency, 'result' => 'excluded', 'exclusion_id' => $exclusion->id];
        }

        return ['results' => $results, 'processed' => count($results)];
    }

    /**
     * @param list<int> $exclusionIds
     * @return array{results: list<array<string, mixed>>, processed: int}
     */
    public function bulkRestore(array $exclusionIds, int $actorId): array
    {
        if (count($exclusionIds) > self::BULK_MAX) {
            throw ValidationException::withMessages(['exclusion_ids' => 'A maximum of '.self::BULK_MAX.' suppliers may be processed per batch.']);
        }
        $realmId = $this->tokens->realmId();
        $results = [];
        foreach ($exclusionIds as $id) {
            $exclusion = QuickBooksSupplierExclusion::query()->where('realm_id', $realmId)->find($id);
            if (! $exclusion) {
                $results[] = ['exclusion_id' => $id, 'result' => 'failed', 'reason' => 'The exclusion does not belong to the connected QuickBooks company.'];
                continue;
            }
            $exclusion->update(['status' => 'restored', 'restored_by' => $actorId, 'restored_at' => now()]);
            $results[] = ['exclusion_id' => $id, 'quickbooks_vendor_id' => $exclusion->quickbooks_vendor_id, 'currency' => $exclusion->currency, 'result' => 'restored'];
        }

        return ['results' => $results, 'processed' => count($results)];
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

        $existingMapping = VendorQuickBooksMapping::query()
            ->where('vendor_id', $vendor->id)
            ->where('realm_id', $realmId)
            ->where('currency', $currency)
            ->first();
        $existingQuickBooksMapping = VendorQuickBooksMapping::query()
            ->where('realm_id', $realmId)
            ->where('quickbooks_vendor_id', $quickbooksVendorId)
            ->first();

        if ($existingQuickBooksMapping && (int) $existingQuickBooksMapping->vendor_id !== (int) $vendor->id) {
            throw new QuickBooksSupplierConflictException(
                'quickbooks_vendor_already_mapped',
                'This QuickBooks supplier is already mapped to another SCM supplier.',
                'Review the existing SCM supplier mapping before approving this proposal.',
                [
                    'realm_id' => $realmId,
                    'quickbooks_vendor_id' => $quickbooksVendorId,
                    'selected_vendor_id' => $vendor->id,
                    'conflicting_vendor_id' => $existingQuickBooksMapping->vendor_id,
                ],
            );
        }

        if ($existingQuickBooksMapping
            && (int) $existingQuickBooksMapping->vendor_id === (int) $vendor->id
            && (string) $existingQuickBooksMapping->currency !== $currency
        ) {
            throw new QuickBooksSupplierConflictException(
                'quickbooks_vendor_currency_mapping_exists',
                'This QuickBooks supplier profile is already mapped for another currency.',
                'Use the QuickBooks supplier profile that matches the requested currency; do not reuse one profile across currencies.',
                [
                    'realm_id' => $realmId,
                    'selected_vendor_id' => $vendor->id,
                    'currency' => $currency,
                    'existing_currency' => $existingQuickBooksMapping->currency,
                    'quickbooks_vendor_id' => $quickbooksVendorId,
                ],
            );
        }

        if ($existingMapping && (string) $existingMapping->quickbooks_vendor_id !== $quickbooksVendorId) {
            throw new QuickBooksSupplierConflictException(
                'supplier_currency_mapping_exists',
                'This SCM supplier already has a different QuickBooks mapping for this realm and currency.',
                'Review the existing mapping; an approved mapping cannot be silently replaced.',
                [
                    'realm_id' => $realmId,
                    'selected_vendor_id' => $vendor->id,
                    'currency' => $currency,
                    'existing_quickbooks_vendor_id' => $existingMapping->quickbooks_vendor_id,
                    'proposed_quickbooks_vendor_id' => $quickbooksVendorId,
                ],
            );
        }

        if ($existingMapping?->status === VendorQuickBooksMapping::STATUS_APPROVED
            && (string) $existingMapping->quickbooks_vendor_id === $quickbooksVendorId) {
            return $existingMapping->fresh();
        }

        $legacyQuickBooksVendorId = trim((string) $vendor->quickbooks_vendor_id);
        if ($currency === PurchaseOrderCurrency::DEFAULT
            && $legacyQuickBooksVendorId !== ''
            && $legacyQuickBooksVendorId !== $quickbooksVendorId
        ) {
            throw new QuickBooksSupplierConflictException(
                'legacy_vendor_id_conflict',
                'The legacy SCM QuickBooks vendor ID differs from the proposed authoritative mapping.',
                'Verify the legacy vendor ID and approve the correct mapping explicitly; no legacy value was overwritten.',
                [
                    'realm_id' => $realmId,
                    'selected_vendor_id' => $vendor->id,
                    'currency' => $currency,
                    'legacy_quickbooks_vendor_id' => $legacyQuickBooksVendorId,
                    'proposed_quickbooks_vendor_id' => $quickbooksVendorId,
                ],
            );
        }

        $quickbooksVendor = $this->quickBooks->readVendor($quickbooksVendorId);
        if ($quickbooksVendor === null) {
            throw ValidationException::withMessages(['quickbooks_vendor_id' => 'The QuickBooks vendor could not be verified.']);
        }
        if ($quickbooksVendor['currency'] !== $currency) {
            throw ValidationException::withMessages(['currency' => 'The QuickBooks vendor currency does not match the requested mapping currency.']);
        }
        $quickbooksEmail = is_string($quickbooksVendor['email'] ?? null)
            ? $quickbooksVendor['email']
            : '';
        $companyName = trim((string) ($quickbooksVendor['CompanyName'] ?? ''));
        $displayName = trim((string) ($quickbooksVendor['DisplayName'] ?? ''));
        $identityName = $companyName !== '' ? $companyName : $displayName;
        $identity = $this->resolution->resolve($identityName, $quickbooksEmail);
        if ($identity['status'] === 'review_required') {
            throw new QuickBooksSupplierConflictException(
                'identity_ambiguous',
                $identity['message'] ?? 'The QuickBooks supplier identity is ambiguous.',
                'Review the candidate SCM supplier IDs and resolve duplicate or conflicting identity evidence before approval.',
                [
                    'realm_id' => $realmId,
                    'selected_vendor_id' => $vendor->id,
                    'quickbooks_vendor_id' => $quickbooksVendorId,
                    'candidate_vendor_ids' => $identity['candidate_vendor_ids'] ?? [],
                    'identity_reason' => $identity['reason'] ?? null,
                    'company_name' => $companyName ?: null,
                    'display_name' => $displayName ?: null,
                    'email' => $quickbooksEmail ?: null,
                ],
            );
        }
        if ($identity['status'] === 'matched' && (int) $identity['vendor']->id !== (int) $vendor->id) {
            throw new QuickBooksSupplierConflictException(
                'identity_match_conflict',
                'The QuickBooks supplier identity matches a different SCM supplier.',
                'Approve the mapping against the matched SCM supplier or resolve the supplier identity before retrying.',
                [
                    'realm_id' => $realmId,
                    'selected_vendor_id' => $vendor->id,
                    'matched_vendor_id' => $identity['vendor']->id,
                    'quickbooks_vendor_id' => $quickbooksVendorId,
                    'matched_on' => $identity['matched_on'] ?? null,
                ],
            );
        }

        if ($companyName !== '' && $displayName !== ''
            && Vendor::normalizeName($companyName) !== Vendor::normalizeName($displayName)
        ) {
            $displayIdentity = $this->resolution->resolve($displayName, $quickbooksEmail);
            $displayCandidates = array_map('intval', $displayIdentity['candidate_vendor_ids'] ?? []);
            if ($displayIdentity['status'] === 'review_required'
                && array_diff($displayCandidates, [(int) $vendor->id]) !== []
            ) {
                throw new QuickBooksSupplierConflictException(
                    'identity_evidence_conflict',
                    'QuickBooks CompanyName and DisplayName provide ambiguous SCM identity evidence.',
                    'Review both QuickBooks names and the candidate SCM supplier IDs before approval.',
                    [
                        'realm_id' => $realmId,
                        'selected_vendor_id' => $vendor->id,
                        'company_name_vendor_id' => $identity['vendor']?->id,
                        'display_name_candidate_vendor_ids' => $displayCandidates,
                        'quickbooks_vendor_id' => $quickbooksVendorId,
                    ],
                );
            }
            if ($displayIdentity['status'] === 'matched'
                && (int) $displayIdentity['vendor']->id !== (int) $vendor->id
            ) {
                throw new QuickBooksSupplierConflictException(
                    'identity_evidence_conflict',
                    'QuickBooks CompanyName and DisplayName identify different SCM suppliers.',
                    'Review both QuickBooks names and select the correct SCM supplier before approval.',
                    [
                        'realm_id' => $realmId,
                        'selected_vendor_id' => $vendor->id,
                        'company_name_vendor_id' => $identity['vendor']?->id,
                        'display_name_vendor_id' => $displayIdentity['vendor']->id,
                        'quickbooks_vendor_id' => $quickbooksVendorId,
                    ],
                );
            }
        }
        if ($identity['status'] !== 'matched' && $matchMethod !== 'manual') {
            throw new QuickBooksSupplierConflictException(
                'identity_unmatched',
                'The QuickBooks supplier could not be matched unambiguously to the selected SCM supplier.',
                'Use manual review only after confirming the supplier identity and supporting evidence.',
                [
                    'realm_id' => $realmId,
                    'selected_vendor_id' => $vendor->id,
                    'quickbooks_vendor_id' => $quickbooksVendorId,
                    'company_name' => $companyName ?: null,
                    'display_name' => $displayName ?: null,
                    'email' => $quickbooksEmail ?: null,
                ],
            );
        }

        $mapping = DB::transaction(function () use ($vendor, $quickbooksVendorId, $currency, $matchMethod, $approvedBy, $realmId) {
            $existingVendorMapping = VendorQuickBooksMapping::query()
                ->where('realm_id', $realmId)
                ->where('quickbooks_vendor_id', $quickbooksVendorId)
                ->lockForUpdate()
                ->first();
            if ($existingVendorMapping && (int) $existingVendorMapping->vendor_id !== (int) $vendor->id) {
                throw new QuickBooksSupplierConflictException(
                    'quickbooks_vendor_already_mapped',
                    'This QuickBooks supplier is already mapped to another SCM supplier.',
                    'Review the existing SCM supplier mapping before approving this proposal.',
                    [
                        'realm_id' => $realmId,
                        'quickbooks_vendor_id' => $quickbooksVendorId,
                        'selected_vendor_id' => $vendor->id,
                        'conflicting_vendor_id' => $existingVendorMapping->vendor_id,
                    ],
                );
            }

            $mapping = VendorQuickBooksMapping::query()
                ->where('vendor_id', $vendor->id)
                ->where('realm_id', $realmId)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();
            if ($mapping && (string) $mapping->quickbooks_vendor_id !== $quickbooksVendorId) {
                throw new QuickBooksSupplierConflictException(
                    'supplier_currency_mapping_exists',
                    'This SCM supplier already has a different QuickBooks mapping for this realm and currency.',
                    'Review the existing mapping; an approved mapping cannot be silently replaced.',
                    [
                        'realm_id' => $realmId,
                        'selected_vendor_id' => $vendor->id,
                        'currency' => $currency,
                        'existing_quickbooks_vendor_id' => $mapping->quickbooks_vendor_id,
                        'proposed_quickbooks_vendor_id' => $quickbooksVendorId,
                    ],
                );
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

    /**
     * @param array<string, mixed> $row
     * @param \Illuminate\Support\Collection<string, VendorQuickBooksMapping> $mappings
     * @return array{code: string, label: string, explanation: string, eligible_import: bool}
     */
    private function classifyUnmatchedVendor(array $row, $mappings, string $realmId): array
    {
        $name = trim((string) ($row['company_name'] ?? $row['display_name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if ($name === '') {
            return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                ? ['code' => 'missing_email', 'label' => 'No email — manual review', 'explanation' => 'A valid email address and usable supplier name are required before import.', 'eligible_import' => false]
                : ['code' => 'insufficient_evidence', 'label' => 'Insufficient evidence', 'explanation' => 'The QuickBooks supplier has no usable name for safe review.', 'eligible_import' => false];
        }
        if (! in_array($currency, PurchaseOrderCurrency::SUPPORTED, true)) {
            return ['code' => 'insufficient_evidence', 'label' => 'Insufficient evidence', 'explanation' => 'The QuickBooks supplier has no supported, identified currency for safe import.', 'eligible_import' => false];
        }

        $sameVendorMappings = $mappings->filter(fn (VendorQuickBooksMapping $mapping) => (string) $mapping->quickbooks_vendor_id === (string) ($row['id'] ?? ''));
        if ($sameVendorMappings->isNotEmpty()) {
            return ['code' => 'quickbooks_identity_overlap', 'label' => 'Duplicate or overlapping QuickBooks identity', 'explanation' => 'This QuickBooks vendor ID already has a currency or SCM mapping in the connected realm.', 'eligible_import' => false];
        }

        $identity = $this->resolution->resolve($name, $email);
        if ($identity['status'] === 'review_required') {
            $reason = (string) ($identity['reason'] ?? '');
            if (in_array($reason, ['multiple_email_matches', 'multiple_name_matches', 'email_name_conflict', 'name_email_conflict'], true)
                && ($identity['candidate_vendor_ids'] ?? []) !== []) {
                return ['code' => 'possible_duplicate', 'label' => 'Possible duplicate of existing SCM supplier', 'explanation' => $identity['message'] ?? 'Existing SCM suppliers require manual identity review.', 'eligible_import' => false];
            }

            return ['code' => 'insufficient_evidence', 'label' => 'Insufficient evidence', 'explanation' => $identity['message'] ?? 'Identity evidence conflicts and requires manual review.', 'eligible_import' => false];
        }
        if ($identity['status'] === 'matched') {
            return ['code' => 'possible_duplicate', 'label' => 'Possible duplicate of existing SCM supplier', 'explanation' => 'The supplier name or email matches an existing SCM supplier; reconcile it instead of importing.', 'eligible_import' => false];
        }

        if ($this->looksLikeIndividualName($name)) {
            return ['code' => 'likely_individual', 'label' => 'Likely individual/person name — manual review', 'explanation' => 'The name resembles a person. Sole proprietors and contractors may still be legitimate; manual review is required, and a valid email is still required for import.', 'eligible_import' => false];
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['code' => 'missing_email', 'label' => 'No email — manual review', 'explanation' => 'A valid email address is required before a supplier can be imported into SCM.', 'eligible_import' => false];
        }

        return ['code' => 'business_import_candidate', 'label' => 'Business import candidate', 'explanation' => 'No existing SCM identity match was found and the record has usable business identity evidence.', 'eligible_import' => true];
    }

    private function looksLikeIndividualName(string $name): bool
    {
        $normalized = Vendor::normalizeName($name);
        if ($normalized === '' || preg_match('/\d|\b(ltd|limited|llc|inc|company|corp|services|enterprise|ventures|trading|agency|plc|group)\b/i', $normalized)) {
            return false;
        }

        $parts = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) && count($parts) >= 2 && count($parts) <= 4 && collect($parts)->every(fn (string $part) => preg_match('/^[a-z\-\']+$/i', $part) === 1);
    }

    /** @param array<string, mixed> $row */
    private function bulkIdentity(array $row): array
    {
        return [
            'quickbooks_vendor_id' => $row['id'] ?? null,
            'currency' => $row['currency'] ?? null,
        ];
    }
}
