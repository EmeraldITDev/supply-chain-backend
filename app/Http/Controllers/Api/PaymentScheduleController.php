<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\PaymentScheduleService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentScheduleController extends Controller
{
    public function __construct(
        private PaymentScheduleService $scheduleService,
    ) {
    }

    public function templates()
    {
        return response()->json([
            'success' => true,
            'data' => $this->scheduleService->listTemplates(),
        ]);
    }

    public function show(string $id)
    {
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $schedule = $this->scheduleService->findForMrf($mrf);

        if (! $schedule) {
            return response()->json([
                'success' => false,
                'error' => 'No payment schedule exists for this MRF',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->scheduleService->toApiArray($schedule),
        ]);
    }

    public function store(Request $request, string $id)
    {
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $this->validateScheduleInput($request);

        try {
            $schedule = $this->scheduleService->create($mrf, $request->user(), $request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->scheduleService->toApiArray($schedule),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $this->validateScheduleInput($request);

        try {
            $schedule = $this->scheduleService->update($mrf, $request->user(), $request->all());
        } catch (ValidationException $e) {
            $status = collect($e->errors())->flatten()->contains(fn ($msg) => str_contains((string) $msg, 'locked'))
                ? 409
                : 422;

            return response()->json([
                'success' => false,
                'error' => $status === 409 ? 'Payment schedule is locked' : 'Validation failed',
                'errors' => $e->errors(),
                'code' => $status === 409 ? 'SCHEDULE_LOCKED' : 'VALIDATION_ERROR',
            ], $status);
        }

        return response()->json([
            'success' => true,
            'data' => $this->scheduleService->toApiArray($schedule),
        ]);
    }

    private function validateScheduleInput(Request $request): void
    {
        $request->validate([
            'template_key' => 'nullable|string',
            'templateKey' => 'nullable|string',
            'milestones' => 'nullable|array|min:1',
            'milestones.*.milestone_number' => 'nullable|integer|min:1',
            'milestones.*.milestoneNumber' => 'nullable|integer|min:1',
            'milestones.*.label' => 'nullable|string|max:255',
            'milestones.*.percentage' => 'nullable|numeric|min:0|max:100',
            'milestones.*.trigger_condition' => 'nullable|string|max:50',
            'milestones.*.triggerCondition' => 'nullable|string|max:50',
            'milestones.*.required_documents' => 'nullable|array',
            'milestones.*.requiredDocuments' => 'nullable|array',
        ]);
    }

    private function findMrf(string $id): ?MRF
    {
        return MRF::query()
            ->where(function ($query) use ($id) {
                $query->where('formatted_id', $id)
                    ->orWhere('mrf_id', $id);

                if (is_numeric($id)) {
                    $query->orWhere('id', (int) $id);
                }
            })
            ->first();
    }
}
