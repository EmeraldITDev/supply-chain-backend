<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MrfBulkActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MrfBulkActionController extends Controller
{
    public function __construct(
        private MrfBulkActionService $bulkActionService,
    ) {
    }

    public function approve(Request $request): JsonResponse
    {
        return $this->handleApproveOrReject($request, 'approve');
    }

    public function reject(Request $request): JsonResponse
    {
        return $this->handleApproveOrReject($request, 'reject');
    }

    private function handleApproveOrReject(Request $request, string $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required',
            'remarks' => 'nullable|string|max:2000',
            'reason' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $remarks = $request->input('remarks') ?? $request->input('reason');
        if ($action === 'reject' && (trim((string) $remarks) === '')) {
            return response()->json([
                'success' => false,
                'error' => 'A reason is required for bulk reject',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $result = $action === 'approve'
            ? $this->bulkActionService->bulkApprove($request->user(), $request->input('ids'), $remarks)
            : $this->bulkActionService->bulkReject($request->user(), $request->input('ids'), $remarks);

        $succeeded = count($result['succeeded']);
        $failed = count($result['failed']);

        return response()->json([
            'success' => $failed === 0,
            'message' => $action === 'approve'
                ? "Bulk approve finished: {$succeeded} succeeded, {$failed} failed"
                : "Bulk reject finished: {$succeeded} succeeded, {$failed} failed",
            'data' => $result,
        ], $succeeded > 0 ? 200 : 422);
    }

    public function export(Request $request): JsonResponse|StreamedResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required',
            'format' => 'nullable|in:json,csv',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $rows = $this->bulkActionService->exportRows($request->input('ids'));
        $format = strtolower((string) $request->input('format', 'json'));

        if ($format === 'csv') {
            $filename = 'mrf-export-'.now()->format('Ymd-His').'.csv';

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                if ($rows === []) {
                    fputcsv($out, ['mrfId', 'title', 'status', 'workflowState']);
                    fclose($out);

                    return;
                }
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($out, array_map(static function ($value) {
                        if (is_array($value) || is_object($value)) {
                            return json_encode($value);
                        }

                        return $value;
                    }, $row));
                }
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => ['count' => count($rows)],
        ]);
    }
}
