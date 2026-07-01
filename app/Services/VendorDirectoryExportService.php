<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use App\Models\VendorRegistrationDocument;
use App\Support\VendorCategoryDisplay;
use App\Support\ExportLimit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorDirectoryExportService
{
    public const EXPORT_ROLES = [
        'procurement_manager',
        'supply_chain_director',
        'executive',
        'logistics_manager',
    ];

    /**
     * @var array<string, string>
     */
    public const COLUMN_LABELS = [
        'vendor_id' => 'Vendor ID',
        'company_name' => 'Company Name',
        'category' => 'Category',
        'email' => 'Email Address',
        'phone' => 'Phone Number',
        'address' => 'Address',
        'tax_id' => 'Tax ID',
        'contact_person' => 'Contact Person',
        'bank_name' => 'Bank Name',
        'account_number' => 'Account Number',
        'account_name' => 'Account Name',
        'currency' => 'Currency',
        'registration_status' => 'Registration Status',
        'registration_date' => 'Registration Date',
        'document_status' => 'Document Status',
    ];

    public function __construct(
        private ReportExportService $exportService,
        private PurchaseOrderPdfService $purchaseOrderPdfService,
    ) {
    }

    public function userCanExport(?User $user): bool
    {
        return $user !== null && in_array($user->scmRole(), self::EXPORT_ROLES, true);
    }

    /**
     * @return list<string>
     */
    public function resolveRequestedColumns(Request $request): array
    {
        $raw = $request->input('columns', $request->query('columns', ''));
        $keys = is_array($raw)
            ? $raw
            : array_filter(array_map('trim', explode(',', (string) $raw)));

        $valid = array_values(array_intersect($keys, array_keys(self::COLUMN_LABELS)));

        return $valid;
    }

    /**
     * @param  Builder<Vendor>  $query
     * @return Builder<Vendor>
     */
    public function applyDirectoryFilters(Builder $query, Request $request): Builder
    {
        $includeInactive = $request->boolean('include_inactive')
            || $request->boolean('includeInactive');

        if (! $includeInactive) {
            $query->where('status', '!=', 'Inactive');
        }

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('category') && $request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->search).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('vendor_id', 'like', $term);
            });
        }

        return $query;
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        if ($this->resolveRequestedColumns($request) === []) {
            abort(422, 'Select at least one column to export.');
        }

        $dataset = $this->buildExportDataset($request);
        $headers = $dataset['headers'];
        $rows = $dataset['rows'];

        $filename = 'Vendor_Directory_'.now()->format('Y-m-d');

        return match ($format) {
            'pdf' => $this->streamBrandedPdf($filename, $headers, $rows, $request),
            'xlsx' => $this->exportService->streamXlsx($filename, $headers, $rows),
            default => abort(422, 'Invalid export format. Use pdf or xlsx.'),
        };
    }

    /**
     * @return \Illuminate\Support\Collection<int, Vendor>
     */
    private function fetchVendors(Request $request)
    {
        $query = Vendor::query();
        $this->applyDirectoryFilters($query, $request);

        $limit = ExportLimit::fromRequest($request);

        return $query
            ->orderBy('name')
            ->limit($limit)
            ->get([
                'id', 'vendor_id', 'name', 'category', 'category_other', 'status',
                'email', 'phone', 'address', 'tax_id', 'contact_person',
                'bank_name', 'account_name', 'account_number', 'created_at',
            ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Vendor>  $vendors
     * @return \Illuminate\Support\Collection<int, VendorRegistration>
     */
    private function loadLatestRegistrations($vendors)
    {
        $vendorIds = $vendors->pluck('id')->filter()->values();

        if ($vendorIds->isEmpty()) {
            return collect();
        }

        return VendorRegistration::query()
            ->whereIn('vendor_id', $vendorIds)
            ->orderByDesc('id')
            ->get()
            ->unique('vendor_id')
            ->keyBy('vendor_id');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, VendorRegistration>  $registrationMap
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function loadDocumentStatuses($registrationMap)
    {
        $registrationIds = $registrationMap->pluck('id')->filter()->values();

        if ($registrationIds->isEmpty()) {
            return collect();
        }

        $documents = VendorRegistrationDocument::query()
            ->whereIn('vendor_registration_id', $registrationIds)
            ->get(['vendor_registration_id', 'status']);

        $statuses = collect();

        foreach ($registrationMap as $registration) {
            $statuses[$registration->id] = $this->resolveDocumentStatus(
                $registration,
                $documents->where('vendor_registration_id', $registration->id),
            );
        }

        return $statuses;
    }

    private function resolveDocumentStatus(VendorRegistration $registration, $documents): string
    {
        if ($registration->status === 'Documents Incomplete') {
            return 'Documents Incomplete';
        }

        if ($documents->isEmpty()) {
            $meta = $registration->getDocumentsMetadataList();

            return $meta === [] ? 'No documents' : 'Complete';
        }

        if ($documents->contains(fn ($doc) => ($doc->status ?? '') === 'Expired')) {
            return 'Expired';
        }

        if ($documents->contains(fn ($doc) => in_array($doc->status ?? '', ['Pending', 'Rejected'], true))) {
            return 'Incomplete';
        }

        return 'Complete';
    }

    private function resolveCellValue(
        Vendor $vendor,
        ?VendorRegistration $registration,
        string $documentStatus,
        string $key,
    ): string {
        return match ($key) {
            'vendor_id' => (string) ($vendor->vendor_id ?? ''),
            'company_name' => (string) ($vendor->name ?? ''),
            'category' => VendorCategoryDisplay::format($vendor->category, $vendor->category_other),
            'email' => (string) ($vendor->email ?? ''),
            'phone' => (string) ($vendor->phone ?? ''),
            'address' => (string) ($vendor->address ?? ''),
            'tax_id' => (string) ($vendor->tax_id ?? ''),
            'contact_person' => (string) ($vendor->contact_person ?? ''),
            'bank_name' => (string) ($vendor->bank_name ?? $registration?->bank_name ?? ''),
            'account_number' => (string) ($vendor->account_number ?? $registration?->account_number ?? ''),
            'account_name' => (string) ($vendor->account_name ?? $registration?->account_name ?? ''),
            'currency' => (string) ($registration?->currency ?? 'NGN'),
            'registration_status' => (string) ($registration?->status ?? $vendor->status ?? ''),
            'registration_date' => $this->formatDate($registration?->created_at ?? $vendor->created_at),
            'document_status' => $documentStatus,
            default => '',
        };
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function streamBrandedPdf(string $filename, array $headers, array $rows, Request $request): StreamedResponse
    {
        $companyName = env('COMPANY_NAME', 'Emerald Industrial Co. CFZE');
        $companyAddress = env('COMPANY_ADDRESS', '');

        $filterNote = $this->filterNote($request);

        $html = view('reports.vendor-directory-export', [
            'title' => 'Vendor Directory',
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'logoHtml' => $this->purchaseOrderPdfService->logoHtml(),
            'headers' => $headers,
            'rows' => $rows,
            'generatedAt' => now()->toDateTimeString(),
            'filterNote' => $filterNote,
            'recordCount' => count($rows),
        ])->render();

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $binary = $dompdf->output();

        return response()->streamDownload(static function () use ($binary): void {
            echo $binary;
        }, $filename.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function filterNote(Request $request): ?string
    {
        $hasFilters = $request->filled('search')
            || ($request->has('status') && $request->filled('status'))
            || ($request->has('category') && $request->filled('category'));

        return $hasFilters
            ? 'Exporting filtered results only. Clear filters to export all vendors.'
            : null;
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, filterNote: ?string}
     */
    public function buildExportDataset(Request $request): array
    {
        $columns = $this->resolveRequestedColumns($request);
        if ($columns === []) {
            abort(422, 'Select at least one column to export.');
        }

        $vendors = $this->fetchVendors($request);
        $registrationMap = $this->loadLatestRegistrations($vendors);
        $documentStatusMap = $this->loadDocumentStatuses($registrationMap);

        $headers = array_map(fn (string $key) => self::COLUMN_LABELS[$key], $columns);
        $rows = $vendors->map(function (Vendor $vendor) use ($columns, $registrationMap, $documentStatusMap) {
            $registration = $registrationMap->get($vendor->id);
            $documentStatus = $documentStatusMap->get($registration?->id ?? 0, 'N/A');

            return array_map(
                fn (string $key) => $this->resolveCellValue($vendor, $registration, $documentStatus, $key),
                $columns,
            );
        })->values()->all();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'filterNote' => $this->filterNote($request),
        ];
    }
}
