<?php

namespace App\Services;

use App\Models\GeneratedReport;
use App\Models\MRF;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
            $search = '%'.trim((string) $request->query('search')).'%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                    ->orWhere('mrf_id', 'like', $search)
                    ->orWhere('formatted_id', 'like', $search);
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

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function parsePeriod(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : null;

        return [$from, $to];
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
        ];
    }
}
