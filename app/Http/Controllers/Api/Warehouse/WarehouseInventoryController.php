<?php

namespace App\Http\Controllers\Api\Warehouse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WarehouseInventoryController extends Controller
{
    public function listLocations(Request $request): JsonResponse
    {
        $tree = $request->boolean('tree');

        return $this->success([
            'locations' => [],
            'tree' => $tree ? [] : null,
        ]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'level' => ['required', 'in:warehouse,zone,aisle,rack,shelf,bin'],
            'parent_id' => ['nullable', 'integer'],
            'capacity' => ['nullable', 'numeric'],
            'occupied' => ['nullable', 'numeric'],
            'utilization_percent' => ['nullable', 'numeric'],
            'item_count' => ['nullable', 'integer'],
        ]);

        return $this->success([
            'location' => array_merge($payload, ['id' => 1]),
        ], 201);
    }

    public function updateLocation(Request $request, int $id): JsonResponse
    {
        return $this->success([
            'location' => ['id' => $id, ...$request->all()],
        ]);
    }

    public function destroyLocation(int $id): JsonResponse
    {
        return $this->success([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    public function listItems(Request $request): JsonResponse
    {
        return $this->success([
            'items' => [],
            'meta' => [
                'search' => $request->query('search'),
                'page' => (int) $request->query('page', 1),
            ],
        ]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'category' => ['nullable', 'string'],
            'uom' => ['nullable', 'string'],
        ]);

        return $this->success([
            'item' => array_merge($payload, ['id' => 1]),
        ], 201);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        return $this->success([
            'item' => ['id' => $id, ...$request->all()],
        ]);
    }

    public function destroyItem(int $id): JsonResponse
    {
        return $this->success([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    public function lookupItems(Request $request): JsonResponse
    {
        return $this->success([
            'items' => [],
            'code' => $request->query('code'),
        ]);
    }

    public function uploadItemAttachments(Request $request, int $id): JsonResponse
    {
        $files = $request->file('attachments');
        $stored = [];

        foreach ($files ?? [] as $index => $file) {
            $path = $file->store('warehouse/attachments', 's3');
            $stored[] = [
                'index' => $index,
                'path' => $path,
                'disk' => 's3',
                'url' => Storage::disk('s3')->url($path),
            ];
        }

        return $this->success([
            'item_id' => $id,
            'attachments' => $stored,
        ], 201);
    }

    public function inventory(Request $request): JsonResponse
    {
        return $this->success([
            'inventory' => [],
            'filters' => [
                'search' => $request->query('search'),
                'location_id' => $request->query('location_id'),
                'category' => $request->query('category'),
                'low_stock' => $request->query('low_stock'),
                'quarantined' => $request->query('quarantined'),
            ],
        ]);
    }

    public function lowStockInventory(): JsonResponse
    {
        return $this->success([
            'items' => [],
        ]);
    }

    public function quarantineInventory(Request $request, int $id): JsonResponse
    {
        return $this->success([
            'inventory_id' => $id,
            'quarantined' => true,
            'note' => $request->input('note'),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        return $this->success([
            'movements' => [],
            'filters' => $request->query(),
        ]);
    }

    public function transfer(Request $request): JsonResponse
    {
        return $this->success([
            'movement' => ['type' => 'transfer', ...$request->all()],
        ], 201);
    }

    public function adjustment(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'reason_code' => ['required', 'string'],
            'note' => ['required', 'string'],
        ]);

        return $this->success([
            'movement' => ['type' => 'adjustment', ...$payload],
        ], 201);
    }

    public function approveMovement(Request $request, int $id): JsonResponse
    {
        return $this->success([
            'movement_id' => $id,
            'approved_by' => $request->user()?->id,
            'approved_at' => now()->toISOString(),
        ]);
    }

    public function vendorReturn(Request $request): JsonResponse
    {
        return $this->success([
            'return' => $request->all(),
        ], 201);
    }

    public function goodsReceipts(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'po_id' => ['required', 'integer'],
            'mrf_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array'],
            'lines.*.po_line_id' => ['required', 'integer'],
            'lines.*.qty_received' => ['required', 'numeric'],
            'lines.*.location_id' => ['required', 'integer'],
            'lines.*.batch' => ['nullable', 'string'],
            'lines.*.lot' => ['nullable', 'string'],
            'lines.*.serial' => ['nullable', 'string'],
            'lines.*.expiry' => ['nullable', 'date'],
        ]);

        return $this->success([
            'goods_receipt' => array_merge($payload, ['id' => 1]),
        ], 201);
    }

    public function stockCounts(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            return $this->success([
                'stock_count' => ['id' => 1, ...$request->all()],
            ], 201);
        }

        return $this->success([
            'stock_counts' => [],
        ]);
    }

    public function stockCountShow(int $id): JsonResponse
    {
        return $this->success([
            'stock_count' => ['id' => $id],
        ]);
    }

    public function stockCountLines(Request $request, int $id): JsonResponse
    {
        return $this->success([
            'stock_count_id' => $id,
            'lines' => $request->all(),
        ], 201);
    }

    public function approveStockCount(Request $request, int $id): JsonResponse
    {
        return $this->success([
            'stock_count_id' => $id,
            'approved_by' => $request->user()?->id,
        ]);
    }

    public function postStockCount(Request $request, int $id): JsonResponse
    {
        return $this->success([
            'stock_count_id' => $id,
            'posted' => true,
        ]);
    }

    public function dashboard(): JsonResponse
    {
        return $this->success([
            'dashboard' => [
                'inventory_value' => 0,
                'stock_items' => 0,
                'available' => 0,
                'reserved' => 0,
                'low_stock_alerts' => 0,
                'overstock_alerts' => 0,
                'dead_stock' => 0,
                'utilization' => 0,
                'recent_goods_receipts' => [],
                'pending_stock_counts' => [],
                'recent_movements' => [],
            ],
        ]);
    }

    public function reports(string $report): JsonResponse
    {
        return $this->success([
            'report' => $report,
            'rows' => [],
        ]);
    }

    public function exportReport(Request $request): JsonResponse
    {
        $format = $request->query('format', 'csv');

        return $this->success([
            'export' => [
                'format' => $format,
                'download_url' => null,
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    private function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }
}
