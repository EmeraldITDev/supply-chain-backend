<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreReportRequest;
use App\Models\Logistics\Report;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function store(StoreReportRequest $request)
    {
        $data = $request->validated();
        $data['status'] = Report::STATUS_SUBMITTED;
        $data['submitted_at'] = now();
        $data['created_by'] = $request->user()?->id;

        $report = Report::create($data);

        $this->auditLogger->log('report_submitted', $request->user(), 'report', (string) $report->id, $data, $request);

        return $this->success([
            'report' => $report,
        ], 201);
    }

    public function index(Request $request)
    {
        $query = Report::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->success([
            'reports' => $query->paginate(20),
        ]);
    }

    public function pending()
    {
        return $this->success([
            'reports' => Report::where('status', Report::STATUS_PENDING)->paginate(20),
        ]);
    }
}
