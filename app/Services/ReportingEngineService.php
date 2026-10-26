<?php

namespace App\Services;

use App\Models\GeneratedReport;
use App\Models\MRF;
use App\Models\MRFItem;
use App\Support\ExportLimit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingEngineService
{
    private const ALLOWED_SORTS = [
        'created_at', 'updated_at', 'workflow_state', 'department', 'estimated_cost', 'mrf_id',
    ];

    public function __construct(private ReportExportService $exportService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function procurementRecords(Request $request): array
    {
        [$from, $to] = $this->parsePeriod($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $sortBy = in_array($request->query('sort_by'), self::ALLOWED_SORTS, true)
            ? $request->query('sort_by')
            : 'created_at';
        $sortDirection = strtolower((string) $request->query('sort_direction')) === 'asc' ? 'asc' : 'desc';

        $query = MRF::query()
            ->select([
                'id',
                'mrf_id',
                'formatted_id',
                'title',
                'department',
                'workflow_state',
                'status',
                'selected_vendor_id',
                'estimated_cost',
                'created_at',
                'updated_at',
                'po_signed_at',
            ])
            ->with(['selectedVendor:id,name']);

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        if ($request->filled('department')) {
            $query->where('department', $request->query('department'));
        }
        if ($request->filled('vendor_id')) {
            $query->where('selected_vendor_id', (int) $request->query('vendor_id'));
        }
        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            $query->where(function ($q) use ($status) {
                $q->where('status', $status)->orWhere('workflow_state', $status);
            });
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($term, $like) {
                $q->where('mrf_id', 'like', $like)
                    ->orWhere('formatted_id', 'like', $like);
                if (is_numeric($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }

        $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($perPage);

        return [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'items' => collect($paginator->items())->map(fn (MRF $mrf) => $this->mapProcurementRow($mrf))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function procurementRecordDetail(int $id): array
    {
        $mrf = MRF::query()
            ->with(['selectedVendor:id,name', 'items:id,mrf_id,item_name,budget_amount,quoted_amount'])
            ->findOrFail($id);

        return [
            'record' => array_merge($this->mapProcurementRow($mrf), [
                'items' => $mrf->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->item_name,
                    'budgetAmount' => (float) ($item->budget_amount ?? 0),
                    'quotedAmount' => (float) ($item->quoted_amount ?? 0),
                ])->values()->all(),
            ]),
        ];
    }

    public function poRegister(Request $request): array
    {
        [$periodStart, $periodEnd, $dateField] = $this->parsePoRegisterPeriod($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $sortBy = in_array($request->query('sort_by'), ['po_generated_at', 'po_signed_at', 'estimated_cost', 'po_number', 'mrf_id', 'department', 'contract_type', 'created_at'], true)
            ? $request->query('sort_by')
            : 'po_generated_at';
        $sortDirection = strtolower((string) $request->query('sort_direction')) === 'asc' ? 'asc' : 'desc';

        $query = $this->basePoRegisterQuery();
        $this->applyPoRegisterFilters($query, $request, $dateField, $periodStart, $periodEnd);
        $summary = $this->poRegisterSummary(clone $query);

        $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($perPage);

        return [
            'period' => ['from' => $periodStart->toDateString(), 'to' => $periodEnd->toDateString()],
            'summary' => $summary,
            'items' => collect($paginator->items())->map(fn (MRF $mrf) => $this->mapPoRegisterRow($mrf))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function exportPoRegister(Request $request, string $format): StreamedResponse|JsonResponse
    {
        $limit = ExportLimit::fromRequest($request);
        [$periodStart, $periodEnd, $dateField] = $this->parsePoRegisterPeriod($request);
        $query = $this->basePoRegisterQuery();
        $this->applyPoRegisterFilters($query, $request, $dateField, $periodStart, $periodEnd);
        $matchingCount = (clone $query)->count();
        if ($matchingCount > $limit) {
            return response()->json([
                'success' => false,
                'error' => "The filtered export contains {$matchingCount} records, exceeding the {$limit}-record export limit. Add filters and retry.",
            ], 422);
        }

        $items = (clone $query)
            ->orderBy('po_generated_at', 'desc')
            ->get()
            ->map(fn (MRF $mrf) => $this->mapPoRegisterRow($mrf))
            ->all();
        $summary = $this->poRegisterSummary(clone $query);
        $filename = 'po-register-'.now()->format('Y-m-d');
        $headers = ['PO Number', 'PO Version', 'Linked MRF', 'PO Title', 'Vendor', 'Department', 'Contract Type', 'Currency', 'PO Value', 'Tax Amount', 'PO Generation Date', 'Signing Date', 'Approval Status', 'Signing Status', 'Payment Status', 'GRN Status', 'Expected Delivery'];
        $poRows = array_map(fn (array $row) => [
            $row['poNumber'] ?? '',
            $row['poVersion'] ?? '',
            $row['mrfNumber'] ?? '',
            $row['title'] ?? '',
            $row['vendorName'] ?? '',
            $row['department'] ?? '',
            $row['contractType'] ?? '',
            $row['currency'] ?? '',
            $row['poValue'] ?? 0,
            $row['taxAmount'] ?? 0,
            $row['poGenerationDate'] ?? '',
            $row['poSigningDate'] ?? '',
            $row['approvalStatus'] ?? '',
            $row['signingStatus'] ?? '',
            $row['paymentStatus'] ?? '',
            $row['grnStatus'] ?? '',
            $row['expectedDeliveryDate'] ?? '',
        ], $items);

        $filters = $this->formatPoRegisterFiltersForExport($request);
        $filters['From'] = $periodStart->toDateString();
        $filters['To'] = $periodEnd->toDateString();
        $userId = $request->user()?->id;
        $generated = GeneratedReport::create([
            'name' => 'Purchase Order Register export',
            'report_type' => 'po_register',
            'format' => $format,
            'status' => GeneratedReport::STATUS_PROCESSING,
            'filters' => $filters,
            'created_by' => $userId,
        ]);

        $response = match ($format) {
            'pdf' => $this->exportService->streamManagementPdf(
                $filename,
                'Purchase Order Register',
                $this->buildPoRegisterSummaryLabels($summary),
                $filters,
                $headers,
                $poRows,
            ),
            'xlsx' => $this->exportService->streamMultiSheetXlsx($filename, [
                [
                    'title' => 'Executive Summary',
                    'headers' => ['Metric', 'Value'],
                    'rows' => $this->buildExecutiveSummaryRows($summary),
                ],
                [
                    'title' => 'Purchase Order Register',
                    'headers' => $headers,
                    'rows' => $poRows,
                ],
                [
                    'title' => 'PO Line Items',
                    'headers' => ['PO Number', 'MRF Number', 'Item Name', 'Quantity', 'Unit', 'Unit Price', 'Line Total', 'Tax Rate (%)'],
                    'rows' => $this->buildPoLineItemRows(array_column($items, 'id')),
                ],
                [
                    'title' => 'Applied Filters & Metadata',
                    'headers' => ['Field', 'Value'],
                    'rows' => $this->buildFilterMetadataRows($filters),
                ],
            ]),
            default => $this->exportService->streamMultiSheetXlsx($filename, [
                [
                    'title' => 'Executive Summary',
                    'headers' => ['Metric', 'Value'],
                    'rows' => $this->buildExecutiveSummaryRows($summary),
                ],
                [
                    'title' => 'Purchase Order Register',
                    'headers' => $headers,
                    'rows' => $poRows,
                ],
                [
                    'title' => 'PO Line Items',
                    'headers' => ['PO Number', 'MRF Number', 'Item Name', 'Quantity', 'Unit', 'Unit Price', 'Line Total', 'Tax Rate (%)'],
                    'rows' => $this->buildPoLineItemRows(array_column($items, 'id')),
                ],
                [
                    'title' => 'Applied Filters & Metadata',
                    'headers' => ['Field', 'Value'],
                    'rows' => $this->buildFilterMetadataRows($filters),
                ],
            ]),
        };

        $generated->update([
            'status' => GeneratedReport::STATUS_COMPLETED,
            'completed_at' => now(),
            'file_size_bytes' => null,
        ]);

        return $response;
    }

    public function exportProcurementRecords(Request $request, string $format): StreamedResponse
    {
        $request->merge(['per_page' => 10000, 'page' => 1]);
        $payload = $this->procurementRecords($request);
        $rows = $payload['items'];
        $filename = 'procurement-records-'.now()->format('Y-m-d');

        $headers = ['MRF ID', 'Title', 'Department', 'Status', 'Vendor', 'Estimated Cost', 'Created', 'PO Signed'];
        $flatRows = array_map(fn (array $row) => [
            $row['displayId'] ?? $row['mrfId'],
            $row['title'],
            $row['department'] ?? '',
            $row['workflowState'] ?? $row['status'],
            $row['vendorName'] ?? '',
            $row['estimatedCost'] ?? 0,
            $row['createdAt'] ?? '',
            $row['poSignedAt'] ?? '',
        ], $rows);

        $userId = $request->user()?->id;
        $generated = GeneratedReport::create([
            'name' => 'Procurement records export',
            'report_type' => 'procurement',
            'format' => $format,
            'status' => GeneratedReport::STATUS_PROCESSING,
            'filters' => $request->only(['from', 'to', 'department', 'vendor_id', 'status', 'search']),
            'created_by' => $userId,
        ]);

        $response = match ($format) {
            'pdf' => $this->exportService->streamPdf($filename, 'Procurement Records', $headers, $flatRows),
            'xlsx' => $this->exportService->streamXlsx($filename, $headers, $flatRows),
            default => $this->exportService->streamCsv($filename, $headers, $flatRows),
        };

        $generated->update([
            'status' => GeneratedReport::STATUS_COMPLETED,
            'completed_at' => now(),
            'file_size_bytes' => null,
        ]);

        return $response;
    }

    private function basePoRegisterQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return MRF::query()
            ->with(['selectedVendor:id,name,vendor_code', 'procurementManager:id,name'])
            ->whereNotNull('po_number')
            ->where('po_number', '!=', '');
    }

    private function parsePoRegisterPeriod(Request $request): array
    {
        $dateField = in_array((string) $request->query('date_basis', 'po_generated_at'), ['date', 'created_at', 'po_generated_at', 'po_signed_at'], true)
            ? (string) $request->query('date_basis', 'po_generated_at')
            : 'po_generated_at';

        $periodEnd = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : Carbon::now()->endOfDay();
        $periodStart = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : $periodEnd->copy()->subDays(30)->startOfDay();

        return [$periodStart, $periodEnd, $dateField];
    }

    private function applyPoRegisterFilters(\Illuminate\Database\Eloquent\Builder $query, Request $request, string $dateField, Carbon $periodStart, Carbon $periodEnd): void
    {
        $query->where($dateField, '>=', $periodStart)
            ->where($dateField, '<=', $periodEnd);

        if ($request->filled('department')) {
            $department = trim((string) $request->query('department'));
            if ($department !== '') {
                $query->whereRaw('LOWER(department) LIKE ?', ['%'.mb_strtolower($department).'%']);
            }
        }
        if ($request->filled('vendor_id')) {
            $query->where('selected_vendor_id', (int) $request->query('vendor_id'));
        }
        if ($request->filled('vendor')) {
            $vendor = trim((string) $request->query('vendor'));
            if ($vendor !== '') {
                $query->whereHas('selectedVendor', function ($q) use ($vendor): void {
                    $like = '%'.mb_strtolower($vendor).'%';
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(vendor_code) LIKE ?', [$like]);
                });
            }
        }
        if ($request->filled('contract_type')) {
            $query->whereRaw('LOWER(contract_type) = ?', [mb_strtolower(trim((string) $request->query('contract_type')))]);
        }
        if ($request->filled('currency')) {
            $query->whereRaw('UPPER(currency) = ?', [strtoupper(trim((string) $request->query('currency')))]);
        }
        if ($request->filled('procurement_manager_id')) {
            $query->where('procurement_manager_id', (int) $request->query('procurement_manager_id'));
        }
        if ($request->filled('procurement_manager')) {
            $manager = trim((string) $request->query('procurement_manager'));
            if ($manager !== '') {
                $query->whereHas('procurementManager', function ($q) use ($manager): void {
                    $like = '%'.mb_strtolower($manager).'%';
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                });
            }
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->query('payment_status'));
        }
        if ($request->filled('signing_status')) {
            $signingStatus = strtolower((string) $request->query('signing_status'));
            if ($signingStatus === 'signed') {
                $query->where(function ($q): void {
                    $q->whereNotNull('signed_po_url')->where('signed_po_url', '!=', '')
                        ->orWhereNotNull('po_signed_at');
                });
            }
            if ($signingStatus === 'unsigned') {
                $query->where(function ($q): void {
                    $q->where(function ($inner): void {
                        $inner->whereNull('signed_po_url')->orWhere('signed_po_url', '=', '');
                    })->whereNull('po_signed_at');
                });
            }
        }
        if ($request->filled('po_status')) {
            $status = strtolower((string) $request->query('po_status'));
            if ($status === 'generated') {
                $query->whereNotNull('po_number');
            }
            if ($status === 'signed') {
                $query->where(function ($q): void {
                    $q->whereNotNull('signed_po_url')->where('signed_po_url', '!=', '')
                        ->orWhereNotNull('po_signed_at');
                });
            }
            if ($status === 'unsigned') {
                $query->where(function ($q): void {
                    $q->where(function ($inner): void {
                        $inner->whereNull('signed_po_url')->orWhere('signed_po_url', '=', '');
                    })->whereNull('po_signed_at');
                });
            }
            if ($status === 'completed') {
                $query->where(function ($q): void {
                    $q->where('grn_completed', true)
                        ->orWhere('status', 'completed')
                        ->orWhere('workflow_state', 'completed');
                });
            }
        }
        if ($request->filled('grn_status')) {
            $grnStatus = strtolower((string) $request->query('grn_status'));
            if ($grnStatus === 'completed') {
                $query->where('grn_completed', true);
            }
            if ($grnStatus === 'pending') {
                $query->where(function ($q): void {
                    $q->where('grn_completed', false)->orWhereNull('grn_completed');
                });
            }
        }
        if ($request->filled('po_value_min')) {
            $query->whereRaw('COALESCE(po_value, estimated_cost) >= ?', [(float) $request->query('po_value_min')]);
        }
        if ($request->filled('po_value_max')) {
            $query->whereRaw('COALESCE(po_value, estimated_cost) <= ?', [(float) $request->query('po_value_max')]);
        }
        if ($request->filled('po_number')) {
            $query->whereRaw('LOWER(po_number) LIKE ?', ['%'.mb_strtolower(trim((string) $request->query('po_number'))).'%']);
        }
        if ($request->filled('mrf_number')) {
            $mrfNumber = trim((string) $request->query('mrf_number'));
            $query->where(function ($q) use ($mrfNumber): void {
                $like = '%'.mb_strtolower($mrfNumber).'%';
                $q->whereRaw('LOWER(mrf_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(formatted_id) LIKE ?', [$like]);
            });
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            $query->where(function ($q) use ($term): void {
                $like = '%'.mb_strtolower($term).'%';
                $q->whereRaw('LOWER(po_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(mrf_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(formatted_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$like]);
            });
        }
    }

    private function poRegisterSummary(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $baseQuery = clone $query;
        $totalPos = $baseQuery->count();
        $signedPos = (clone $baseQuery)->where(function ($q): void {
            $q->whereNotNull('signed_po_url')->where('signed_po_url', '!=', '')
                ->orWhereNotNull('po_signed_at');
        })->count();
        $unsignedPos = (clone $baseQuery)->where(function ($q): void {
            $q->where(function ($inner): void {
                $inner->whereNull('signed_po_url')->orWhere('signed_po_url', '=', '');
            })->whereNull('po_signed_at');
        })->count();
        $awaitingGrn = (clone $baseQuery)->where(function ($q): void {
            $q->where('grn_completed', false)
                ->orWhereNull('grn_completed');
        })->count();
        $completedGrn = (clone $baseQuery)->where('grn_completed', true)->count();

        $currencyRows = (clone $baseQuery)
            ->select(['currency', DB::raw('COUNT(*) as po_count'), DB::raw('SUM(COALESCE(po_value, estimated_cost, 0)) as total_value'), DB::raw('SUM(COALESCE(tax_amount, 0)) as total_tax'), DB::raw('AVG(COALESCE(po_value, estimated_cost, 0)) as average_value')])
            ->groupBy('currency')
            ->orderByDesc('total_value')
            ->get();

        $currencies = $currencyRows->map(fn ($row) => trim((string) $row->currency) !== '' ? (string) $row->currency : 'Unknown')
            ->unique()
            ->values()
            ->all();
        $totalPoValue = null;
        if (count($currencies) === 1 && $currencyRows->isNotEmpty()) {
            $totalPoValue = (float) $currencyRows->sum('total_value');
        }
        $totalPoValueCurrency = count($currencies) === 1 ? $currencies[0] : null;
        $totalTaxAmount = count($currencies) === 1 && $currencyRows->isNotEmpty()
            ? (float) $currencyRows->sum('total_tax')
            : null;
        $averagePoValue = count($currencies) === 1 && $currencyRows->isNotEmpty()
            ? (float) $currencyRows->first()->average_value
            : null;

        $vendorCurrencyRows = (clone $baseQuery)
            ->join('vendors', 'vendors.id', '=', 'm_r_f_s.selected_vendor_id')
            ->select(['vendors.name as vendor_name', 'm_r_f_s.currency', DB::raw('COUNT(*) as po_count'), DB::raw('SUM(COALESCE(m_r_f_s.po_value, m_r_f_s.estimated_cost, 0)) as total_po_value')])
            ->groupBy('vendors.id', 'vendors.name', 'm_r_f_s.currency')
            ->orderByDesc('total_po_value')
            ->get();
        $topVendorsByCurrency = $vendorCurrencyRows
            ->groupBy(fn ($row) => trim((string) $row->currency) !== '' ? (string) $row->currency : 'Unknown')
            ->map(fn ($vendors, $currency) => [
                'currency' => (string) $currency,
                'vendors' => $vendors->sortByDesc('total_po_value')->take(5)->map(fn ($row) => [
                    'name' => $row->vendor_name,
                    'poCount' => (int) $row->po_count,
                    'totalPoValue' => (float) $row->total_po_value,
                ])->values()->all(),
            ])->values()->all();

        $monthlyTrend = (clone $baseQuery)
            ->select(['po_generated_at', 'po_value', 'estimated_cost', 'currency'])
            ->whereNotNull('po_generated_at')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->po_generated_at)->format('Y-m'))
            ->map(function ($entries, $month) {
                return [
                    'month' => $month,
                    'count' => $entries->count(),
                    'valueByCurrency' => $entries
                        ->groupBy(fn ($row) => trim((string) $row->currency) !== '' ? (string) $row->currency : 'Unknown')
                        ->map(fn ($currencyRows, $currency) => [
                            'currency' => (string) $currency,
                            'totalValue' => (float) $currencyRows->sum(fn ($row) => $row->po_value ?? $row->estimated_cost ?? 0),
                        ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'totalGeneratedPos' => $totalPos,
            'totalPoValue' => $totalPoValue,
            'totalPoValueCurrency' => $totalPoValueCurrency,
            'totalPoValueByCurrency' => $currencyRows->map(fn ($row) => [
                'currency' => trim((string) $row->currency) !== '' ? (string) $row->currency : 'Unknown',
                'count' => (int) ($row->po_count ?? 0),
                'totalValue' => (float) ($row->total_value ?? 0),
                'totalTaxAmount' => (float) ($row->total_tax ?? 0),
                'averagePoValue' => (float) ($row->average_value ?? 0),
            ])->values()->all(),
            'signedPos' => $signedPos,
            'unsignedPos' => $unsignedPos,
            'awaitingGrn' => $awaitingGrn,
            'completedGrn' => $completedGrn,
            'averagePoValue' => $averagePoValue,
            'totalTaxAmount' => $totalTaxAmount,
            'monthlyTrend' => $monthlyTrend,
            'topVendorsByCurrency' => $topVendorsByCurrency,
        ];
    }

    private function buildExecutiveSummaryRows(array $summary): array
    {
        $rows = [
            ['Total Generated POs', $summary['totalGeneratedPos'] ?? 0],
            ['Total PO Value', $summary['totalGeneratedPos'] === 0
                ? 'No matching POs'
                : ($summary['totalPoValue'] === null
                    ? 'Multiple currencies; see currency breakdown'
                    : $this->formatCurrencyForSummary((float) $summary['totalPoValue'], $summary['totalPoValueCurrency'] ?? null))],
            ['Signed POs', $summary['signedPos'] ?? 0],
            ['Unsigned POs', $summary['unsignedPos'] ?? 0],
            ['Awaiting GRN', $summary['awaitingGrn'] ?? 0],
            ['Completed GRN', $summary['completedGrn'] ?? 0],
            ['Average PO Value', $summary['totalGeneratedPos'] === 0
                ? 'No matching POs'
                : ($summary['averagePoValue'] === null
                    ? 'Multiple currencies; see currency breakdown'
                    : $this->formatCurrencyForSummary((float) $summary['averagePoValue'], $summary['totalPoValueCurrency'] ?? null))],
        ];

        foreach (($summary['totalPoValueByCurrency'] ?? []) as $entry) {
            $rows[] = ['Currency Total ('.$entry['currency'].')', $this->formatCurrencyForSummary((float) $entry['totalValue'], (string) $entry['currency'])];
            $rows[] = ['Tax Total ('.$entry['currency'].')', $this->formatCurrencyForSummary((float) $entry['totalTaxAmount'], (string) $entry['currency'])];
            $rows[] = ['Average PO Value ('.$entry['currency'].')', $this->formatCurrencyForSummary((float) $entry['averagePoValue'], (string) $entry['currency'])];
        }

        return $rows;
    }

    private function buildPoRegisterSummaryLabels(array $summary): array
    {
        $labels = [
            'Total Generated POs' => $summary['totalGeneratedPos'] ?? 0,
            'Total PO Value' => $summary['totalGeneratedPos'] === 0
                ? 'No matching POs'
                : ($summary['totalPoValue'] === null
                    ? 'Multiple currencies; see currency breakdown'
                    : $this->formatCurrencyForSummary((float) $summary['totalPoValue'], $summary['totalPoValueCurrency'] ?? null)),
            'Signed POs' => $summary['signedPos'] ?? 0,
            'Unsigned POs' => $summary['unsignedPos'] ?? 0,
            'Awaiting GRN' => $summary['awaitingGrn'] ?? 0,
            'Completed GRN' => $summary['completedGrn'] ?? 0,
            'Average PO Value' => $summary['totalGeneratedPos'] === 0
                ? 'No matching POs'
                : ($summary['averagePoValue'] === null
                    ? 'Multiple currencies; see currency breakdown'
                    : $this->formatCurrencyForSummary((float) $summary['averagePoValue'], $summary['totalPoValueCurrency'] ?? null)),
        ];

        foreach (($summary['totalPoValueByCurrency'] ?? []) as $entry) {
            $currency = (string) $entry['currency'];
            $labels[$currency.' Total'] = $this->formatCurrencyForSummary((float) $entry['totalValue'], $currency);
            $labels[$currency.' Tax'] = $this->formatCurrencyForSummary((float) $entry['totalTaxAmount'], $currency);
            $labels[$currency.' Average PO'] = $this->formatCurrencyForSummary((float) $entry['averagePoValue'], $currency);
        }

        return $labels;
    }

    private function formatPoRegisterFiltersForExport(Request $request): array
    {
        $filterMap = [
            'Report Type' => 'Purchase Order Register',
            'Generated By' => $request->user()?->name ?? 'System',
            'Date Basis' => $request->query('date_basis', 'po_generated_at'),
            'From' => $request->query('from'),
            'To' => $request->query('to'),
            'Department' => $request->query('department'),
            'Vendor' => $request->query('vendor') ?? $request->query('vendor_id'),
            'Contract Type' => $request->query('contract_type'),
            'Currency' => $request->query('currency'),
            'Procurement Manager' => $request->query('procurement_manager') ?? $request->query('procurement_manager_id'),
            'PO Status' => $request->query('po_status'),
            'Signing Status' => $request->query('signing_status'),
            'Payment Status' => $request->query('payment_status'),
            'GRN Status' => $request->query('grn_status'),
            'Minimum PO Value' => $request->query('po_value_min'),
            'Maximum PO Value' => $request->query('po_value_max'),
            'PO Number' => $request->query('po_number'),
            'MRF Number' => $request->query('mrf_number'),
            'Search' => $request->query('search'),
        ];

        return array_filter($filterMap, fn ($value) => $value !== null && $value !== '');
    }

    private function buildFilterMetadataRows(array $filters): array
    {
        $rows = [];
        foreach ($filters as $field => $value) {
            $rows[] = [(string) $field, (string) $value];
        }

        $rows[] = ['Report generated at', now()->toDateTimeString()];
        $rows[] = ['Report scope', 'Generated purchase orders only'];

        return $rows;
    }

    private function buildPoLineItemRows(array $poIds): array
    {
        if ($poIds === []) {
            return [];
        }

        return MRFItem::query()
            ->whereIn('mrf_id', $poIds)
            ->select(['mrf_id', 'item_name', 'quantity', 'unit', 'unit_price', 'total_price', 'tax_rate'])
            ->with('mrf:id,po_number,mrf_id')
            ->get()
            ->map(fn ($item) => [
                $item->mrf?->po_number ?? '',
                $item->mrf?->mrf_id ?? '',
                $item->item_name ?? '',
                $item->quantity ?? '',
                $item->unit ?? '',
                $item->unit_price ?? 0,
                $item->total_price ?? 0,
                $item->tax_rate ?? '',
            ])->values()->all();
    }

    private function formatCurrencyForSummary(?float $value, ?string $currency): string
    {
        if ($value === null) {
            return 'Not Available';
        }

        $symbol = match (strtoupper((string) $currency)) {
            'NGN' => '₦',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper((string) $currency).' ',
        };

        return $symbol.number_format((float) $value, 2);
    }

    private function mapPoRegisterRow(MRF $mrf): array
    {
        $signedStatus = 'Unsigned';
        if (! empty($mrf->signed_po_url) || ! empty($mrf->po_signed_at)) {
            $signedStatus = 'Signed';
        }

        $taxAmount = (float) ($mrf->tax_amount ?? 0);
        $poValue = (float) ($mrf->po_value ?? $mrf->estimated_cost ?? 0);

        return [
            'id' => $mrf->id,
            'poNumber' => $mrf->po_number,
            'poVersion' => $mrf->po_version ?? '1',
            'mrfNumber' => $mrf->formatted_id ?? $mrf->mrf_id,
            'mrfId' => $mrf->mrf_id,
            'title' => $mrf->title,
            'vendorName' => $mrf->selectedVendor?->name ?? 'Not Available',
            'vendorCode' => $mrf->selectedVendor?->vendor_code ?? 'Not Available',
            'department' => $mrf->department ?? 'Not Available',
            'contractType' => $mrf->contract_type ?? 'Not Available',
            'currency' => $mrf->currency ?: 'NGN',
            'poValue' => $poValue,
            'taxAmount' => $taxAmount,
            'poGenerationDate' => $mrf->po_generated_at?->toDateString() ?? 'Not Available',
            'poSigningDate' => $mrf->po_signed_at?->toDateString(),
            'approvalStatus' => $mrf->status ?? $mrf->workflow_state ?? 'Not Available',
            'signingStatus' => $signedStatus,
            'paymentStatus' => $mrf->payment_status ?? 'Not Recorded',
            'grnStatus' => $mrf->grn_completed ? 'Completed' : 'Pending',
            'expectedDeliveryDate' => $mrf->expected_delivery_date?->toDateString() ?? 'Not Available',
            'unsignedPoUrl' => $mrf->unsigned_po_url,
            'signedPoUrl' => $mrf->signed_po_url,
            'generatedAt' => $mrf->po_generated_at?->toIso8601String(),
            'procurementManager' => $mrf->procurementManager?->name ?? 'Not Assigned',
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function parsePeriod(Request $request): array
    {
        $periodEnd = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : Carbon::now()->endOfDay();
        $periodStart = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : $periodEnd->copy()->subDays(30)->startOfDay();

        return [$periodStart, $periodEnd];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProcurementRow(MRF $mrf): array
    {
        return [
            'id' => $mrf->id,
            'mrfId' => $mrf->mrf_id,
            'displayId' => $mrf->formatted_id ?? $mrf->mrf_id,
            'title' => $mrf->title,
            'department' => $mrf->department,
            'status' => $mrf->status,
            'workflowState' => $mrf->workflow_state,
            'vendorId' => $mrf->selected_vendor_id,
            'vendorName' => $mrf->selectedVendor?->name,
            'estimatedCost' => (float) ($mrf->estimated_cost ?? 0),
            'createdAt' => $mrf->created_at?->toIso8601String(),
            'poSignedAt' => $mrf->po_signed_at?->toIso8601String(),
            'detailPath' => '/procurement?mrf='.urlencode((string) ($mrf->formatted_id ?? $mrf->mrf_id)),
            'mrfLinkId' => (string) ($mrf->formatted_id ?? $mrf->mrf_id),
        ];
    }
}
