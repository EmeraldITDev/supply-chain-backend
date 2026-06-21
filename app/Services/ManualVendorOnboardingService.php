<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ManualVendorOnboardingService
{
    public const ONBOARDING_SOURCE_MANUAL_PO = 'manual_po';

    public function __construct(
        private VendorApprovalService $vendorApprovalService,
        private EmailService $emailService,
        private PriceComparisonPoLineService $priceComparisonPoLineService,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(?string $email, ?string $name): ?array
    {
        $email = trim((string) $email);
        $name = trim((string) $name);

        if ($email !== '') {
            $vendor = Vendor::findByEmailCaseInsensitive($email);
            if ($vendor) {
                return $this->formatLookupMatch($vendor, 'email');
            }
        }

        if ($name !== '') {
            $vendor = Vendor::findByNormalizedName($name);
            if ($vendor) {
                return $this->formatLookupMatch($vendor, 'name');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manual
     * @return array{vendor: Vendor, action: string, input: array{name: string, email: string, phone: string|null}}
     */
    public function findOrCreateFromManual(array $manual, ?MRF $mrf = null): array
    {
        $this->assertManualVendorPayload($manual);

        $name = trim((string) $manual['name']);
        $email = trim((string) $manual['email']);
        $input = [
            'name' => $name,
            'email' => $email,
            'phone' => trim((string) ($manual['phone'] ?? '')) ?: null,
        ];

        $existing = Vendor::findByEmailCaseInsensitive($email);
        $matchedOn = 'email';

        if (! $existing && $name !== '') {
            $existing = Vendor::findByNormalizedName($name);
            $matchedOn = 'name';
        }

        if ($existing) {
            $this->fillBlankVendorFields($existing, $manual);

            Log::info('Manual vendor linked to existing directory record', [
                'mrf_id' => $mrf?->mrf_id,
                'vendor_id' => $existing->vendor_id,
                'matched_on' => $matchedOn,
            ]);

            return [
                'vendor' => $existing->fresh(),
                'action' => 'linked_existing',
                'input' => $input,
                'matchedOn' => $matchedOn,
            ];
        }

        $vendor = Vendor::create([
            'vendor_id' => Vendor::generateVendorId(),
            'name' => $name,
            'category' => 'General',
            'rating' => 0,
            'total_orders' => 0,
            'status' => 'Active',
            'email' => $email,
            'phone' => $input['phone'],
            'address' => $this->nullableString($manual['address'] ?? null),
            'contact_person' => $this->nullableString($manual['contact_person'] ?? null),
            'contact_person_email' => $this->nullableString($manual['contact_person_email'] ?? null),
            'notes' => 'Created from procurement price comparison (manual / fast-track).',
            'profile_completed' => false,
            'onboarding_source' => self::ONBOARDING_SOURCE_MANUAL_PO,
        ]);

        Log::info('Manual vendor created from price comparison', [
            'mrf_id' => $mrf?->mrf_id,
            'vendor_id' => $vendor->vendor_id,
        ]);

        return [
            'vendor' => $vendor,
            'action' => 'created',
            'input' => $input,
            'matchedOn' => null,
        ];
    }

    /**
     * Provision portal access + onboarding email for manual-PO vendors when a PO is finalised.
     *
     * @return list<array<string, mixed>>
     */
    public function finalizeVendorsForPoGeneration(MRF $mrf, bool $isRegeneration = false): array
    {
        $mrf->loadMissing(['priceComparisons.vendor']);

        $vendors = $this->priceComparisonPoLineService
            ->selectedSupplierRows($mrf)
            ->pluck('vendor')
            ->filter()
            ->unique('id')
            ->values();

        if ($vendors->isEmpty() && $mrf->selected_vendor_id) {
            $selected = Vendor::query()->find($mrf->selected_vendor_id);
            if ($selected) {
                $vendors = collect([$selected]);
            }
        }

        $resolved = [];
        $seenVendorIds = [];

        foreach ($vendors as $vendor) {
            if (! $vendor instanceof Vendor || in_array($vendor->id, $seenVendorIds, true)) {
                continue;
            }
            $seenVendorIds[] = $vendor->id;

            if (! $this->shouldOnboardForPo($vendor)) {
                continue;
            }

            $resolved[] = $this->onboardVendorForPo($vendor, $isRegeneration);
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function onboardVendorForPo(Vendor $vendor, bool $isRegeneration = false): array
    {
        $input = [
            'name' => (string) $vendor->name,
            'email' => (string) $vendor->email,
        ];

        $portalUser = $this->findPortalUser($vendor);

        if ($isRegeneration && ($portalUser !== null || $vendor->onboarding_email_sent_at !== null)) {
            return [
                'input' => $input,
                'vendorId' => $vendor->vendor_id,
                'action' => 'linked_existing',
                'onboardingEmailSent' => false,
                'status' => $vendor->status,
            ];
        }

        if ($portalUser) {
            return [
                'input' => $input,
                'vendorId' => $vendor->vendor_id,
                'action' => 'linked_existing',
                'onboardingEmailSent' => false,
                'status' => $vendor->status,
            ];
        }

        if ($vendor->onboarding_email_sent_at !== null) {
            return [
                'input' => $input,
                'vendorId' => $vendor->vendor_id,
                'action' => $vendor->onboarding_source === self::ONBOARDING_SOURCE_MANUAL_PO ? 'created' : 'linked_existing',
                'onboardingEmailSent' => false,
                'status' => $vendor->status,
            ];
        }

        if ($isRegeneration) {
            return [
                'input' => $input,
                'vendorId' => $vendor->vendor_id,
                'action' => 'linked_existing',
                'onboardingEmailSent' => false,
                'status' => $vendor->status,
            ];
        }

        $temporaryPassword = $this->vendorApprovalService->generateTemporaryPassword();
        $directoryAction = $vendor->onboarding_source === self::ONBOARDING_SOURCE_MANUAL_PO
            ? 'created'
            : 'linked_existing';

        DB::transaction(function () use ($vendor, $temporaryPassword) {
            $this->vendorApprovalService->createPortalUserForVendor($vendor, $temporaryPassword);

            $updates = [];
            if (Schema::hasColumn('vendors', 'onboarding_source') && empty($vendor->onboarding_source)) {
                $updates['onboarding_source'] = self::ONBOARDING_SOURCE_MANUAL_PO;
            }
            if (Schema::hasColumn('vendors', 'profile_completed') && ! $vendor->profile_completed) {
                $updates['profile_completed'] = false;
            }
            if ($updates !== []) {
                $vendor->update($updates);
            }
        });

        $emailSent = $this->emailService->sendManualPoVendorOnboardingEmail(
            (string) $vendor->email,
            (string) $vendor->name,
            $temporaryPassword,
        );

        if ($emailSent && Schema::hasColumn('vendors', 'onboarding_email_sent_at')) {
            $vendor->update(['onboarding_email_sent_at' => now()]);
        }

        return [
            'input' => $input,
            'vendorId' => $vendor->vendor_id,
            'action' => $directoryAction,
            'onboardingEmailSent' => $emailSent,
            'status' => $vendor->fresh()->status,
        ];
    }

    public function isProfileComplete(Vendor $vendor): bool
    {
        if (Schema::hasColumn('vendors', 'profile_completed') && $vendor->profile_completed === true) {
            return true;
        }

        $category = trim((string) ($vendor->category ?? ''));
        $address = trim((string) ($vendor->address ?? ''));
        $taxId = trim((string) ($vendor->tax_id ?? ''));
        $website = trim((string) ($vendor->website ?? ''));

        if ($category === '' || $category === 'General') {
            return false;
        }

        return $address !== '' && $taxId !== '' && $website !== '';
    }

    public function refreshProfileCompleted(Vendor $vendor): Vendor
    {
        if (! Schema::hasColumn('vendors', 'profile_completed')) {
            return $vendor;
        }

        $vendor->update(['profile_completed' => $this->isProfileComplete($vendor)]);

        return $vendor->fresh();
    }

    /**
     * @param  array<string, mixed>  $manual
     */
    public function assertManualVendorPayload(array $manual): void
    {
        $name = trim((string) ($manual['name'] ?? ''));
        $email = trim((string) ($manual['email'] ?? ''));
        $phone = trim((string) ($manual['phone'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'manual_vendor.name' => ['Supplier name is required for a manual vendor row.'],
            ]);
        }

        if ($email === '') {
            throw ValidationException::withMessages([
                'manual_vendor.email' => ['Supplier email is required for manual vendors.'],
            ]);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'manual_vendor.email' => ['Supplier email must be a valid email address.'],
            ]);
        }

        if ($phone === '') {
            throw ValidationException::withMessages([
                'manual_vendor.phone' => ['Supplier phone is required for manual vendors.'],
            ]);
        }
    }

    private function shouldOnboardForPo(Vendor $vendor): bool
    {
        if ($this->isPlaceholderEmail((string) $vendor->email)) {
            return false;
        }

        if ($this->findPortalUser($vendor) !== null) {
            return false;
        }

        return $vendor->onboarding_source === self::ONBOARDING_SOURCE_MANUAL_PO
            || str_contains(strtolower((string) ($vendor->notes ?? '')), 'price comparison (manual');
    }

    private function findPortalUser(Vendor $vendor): ?User
    {
        if (Schema::hasColumn('users', 'vendor_id')) {
            $byVendorId = User::query()->where('vendor_id', $vendor->id)->first();
            if ($byVendorId) {
                return $byVendorId;
            }
        }

        return User::findByEmailCaseInsensitive((string) $vendor->email);
    }

    /**
     * @param  array<string, mixed>  $manual
     */
    private function fillBlankVendorFields(Vendor $vendor, array $manual): void
    {
        $updates = [];

        foreach ([
            'phone' => 'phone',
            'address' => 'address',
            'contact_person' => 'contact_person',
            'contact_person_email' => 'contact_person_email',
        ] as $manualKey => $vendorKey) {
            $incoming = $this->nullableString($manual[$manualKey] ?? null);
            if ($incoming !== null && trim((string) ($vendor->{$vendorKey} ?? '')) === '') {
                $updates[$vendorKey] = $incoming;
            }
        }

        if ($updates !== []) {
            $vendor->update($updates);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLookupMatch(Vendor $vendor, string $matchedOn): array
    {
        return [
            'id' => $vendor->vendor_id,
            'name' => $vendor->name,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'status' => $vendor->status,
            'matchedOn' => $matchedOn,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isPlaceholderEmail(string $email): bool
    {
        return str_contains(strtolower($email), '@supplier.placeholder');
    }
}
