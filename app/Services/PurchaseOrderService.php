<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FormattedIdGenerator;
use App\Support\PurchaseOrderCurrency;
use App\Support\RequestLineItemParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    /** Columns required for PO list table rows. */
    public const LIST_SELECT = [
        'id', 'mrf_id', 'formatted_id', 'po_number', 'title', 'status', 'workflow_state',
        'current_stage', 'estimated_cost', 'currency', 'selected_vendor_id', 'requester_name',
        'department', 'po_draft_saved_at', 'po_generated_at', 'unsigned_po_url', 'signed_po_url',
        'source', 'is_po_linked', 'linked_po_id', 'created_at', 'updated_at',
    ];

    /** Columns required to populate the PO edit modal. */
    public const EDIT_SELECT = [
        'id', 'mrf_id', 'formatted_id', 'po_number', 'title', 'category', 'contract_type',
        'status', 'workflow_state', 'current_stage', 'estimated_cost', 'currency',
        'ship_to_address', 'tax_rate', 'tax_amount', 'custom_terms', 'po_terms_mode',
        'po_special_terms', 'invoice_submission_email', 'invoice_submission_cc',
        'selected_vendor_id', 'requester_id', 'requester_name', 'department',
        'po_draft_saved_at', 'po_generated_at', 'unsigned_po_url', 'signed_po_url',
        'source', 'is_po_linked', 'linked_po_id', 'justification', 'created_at', 'updated_at',
    ];

    public function __construct(
        private FormattedIdGenerator $formattedIdGenerator,
        private LineItemBudgetService $lineItemBudgetService,
        private PaymentScheduleService $paymentScheduleService,
    ) {
    }

    public function listQuery(\Illuminate\Http\Request $request): Builder
    {
        $query = MRF::query()
            ->select(self::LIST_SELECT)
            ->forPoList();

        if ($request->filled('status') && strtolower((string) $request->status) !== 'all') {
            $query->withPoLifecycleStatus((string) $request->status);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            if ($search !== '') {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $prefix = $escaped.'%';
                $contains = '%'.$escaped.'%';
                $query->where(function ($q) use ($prefix, $contains, $search) {
                    $q->where('mrf_id', 'like', $prefix)
                        ->orWhere('formatted_id', 'like', $prefix)
                        ->orWhere('po_number', 'like', $prefix)
                        ->orWhere('linked_po_id', 'like', $prefix);
                    if (strlen($search) >= 3) {
                        $q->orWhere('requester_name', 'like', $contains);
                    }
                });
            }
        }

        $sortBy = (string) $request->input('sort_by', $request->input('sortBy', 'updated_at'));
        $allowedSort = ['updated_at', 'created_at', 'po_number', 'po_generated_at', 'po_draft_saved_at', 'title'];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'updated_at';
        }

        $direction = strtolower((string) $request->input('sort_direction', $request->input('sortOrder', 'desc')));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        return $query->orderBy($sortBy, $direction);
    }

    /**
     * @return array<string, mixed>
     */
    public function mapListRow(MRF $mrf): array
    {
        $vendor = $mrf->relationLoaded('selectedVendor') ? $mrf->selectedVendor : null;
        $poNumber = $mrf->effectivePoNumber();

        return array_merge($mrf->poOriginApiFields(), $mrf->poDraftApiFields(), [
            'id' => $mrf->mrf_id,
            'numericId' => $mrf->id,
            'formattedId' => $mrf->formatted_id,
            'po_number' => $poNumber,
            'poNumber' => $poNumber,
            'title' => $mrf->title,
            'status' => $mrf->status,
            'workflowState' => $mrf->workflow_state,
            'currentStage' => $mrf->current_stage,
            'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
            'currency' => PurchaseOrderCurrency::normalize($mrf->currency),
            'requesterName' => $mrf->requester_name,
            'department' => $mrf->department,
            'vendor' => $vendor ? [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
            ] : null,
            'poGeneratedAt' => $mrf->po_generated_at?->toIso8601String(),
            'createdAt' => $mrf->created_at?->toIso8601String(),
            'updatedAt' => $mrf->updated_at?->toIso8601String(),
        ]);
    }

    public function findForEdit(string $id): ?MRF
    {
        return MRF::query()
            ->select(self::EDIT_SELECT)
            ->where(function ($query) use ($id) {
                $query->where('formatted_id', $id)
                    ->orWhere('mrf_id', $id)
                    ->orWhere('po_number', $id)
                    ->orWhere('linked_po_id', $id);

                if (is_numeric($id)) {
                    $query->orWhere('id', (int) $id);
                }
            })
            ->with([
                'items:id,mrf_id,item_name,description,quantity,unit,unit_price,total_price,budget_amount,quoted_amount',
                'priceComparisons:id,purchase_order_id,vendor_id,item_description,unit_price,quantity,total_price,is_selected,selection_reason',
                'priceComparisons.vendor:id,vendor_id,name,email,phone,address,contact_person',
                'selectedVendor:id,vendor_id,name,email,phone,address,contact_person,contact_person_email',
                'paymentSchedule.milestones',
            ])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function mapEditPayload(MRF $mrf): array
    {
        $paymentMilestones = $this->paymentScheduleService->paymentMilestonesForMrf($mrf);

        $poNumber = $mrf->effectivePoNumber();

        return array_merge($mrf->poOriginApiFields(), $mrf->poDraftApiFields(), $mrf->currencyApiFields(), [
            'id' => $mrf->mrf_id,
            'numericId' => $mrf->id,
            'formattedId' => $mrf->formatted_id,
            'po_number' => $poNumber,
            'poNumber' => $poNumber,
            'title' => $mrf->title,
            'category' => $mrf->category,
            'contractType' => $mrf->contract_type,
            'status' => $mrf->status,
            'workflowState' => $mrf->workflow_state,
            'currentStage' => $mrf->current_stage,
            'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
            'shipToAddress' => $mrf->ship_to_address,
            'taxRate' => $mrf->tax_rate !== null ? (float) $mrf->tax_rate : 0,
            'taxAmount' => $mrf->tax_amount !== null ? (float) $mrf->tax_amount : 0,
            'customTerms' => $mrf->custom_terms,
            'poTermsMode' => $mrf->po_terms_mode,
            'poSpecialTerms' => $mrf->po_special_terms,
            'invoiceSubmissionEmail' => $mrf->invoice_submission_email,
            'invoiceSubmissionCc' => $mrf->invoice_submission_cc,
            'requesterName' => $mrf->requester_name,
            'department' => $mrf->department,
            'selectedVendor' => $mrf->selectedVendor ? [
                'id' => $mrf->selectedVendor->vendor_id,
                'name' => $mrf->selectedVendor->name,
                'email' => $mrf->selectedVendor->email,
                'phone' => $mrf->selectedVendor->phone,
                'address' => $mrf->selectedVendor->address,
            ] : null,
            'items' => $mrf->items->map(fn ($item) => [
                'id' => $item->id,
                'itemName' => $item->item_name,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unitPrice' => $item->unit_price !== null ? (float) $item->unit_price : null,
                'totalPrice' => $item->total_price !== null ? (float) $item->total_price : null,
                'budgetAmount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
                'quotedAmount' => $item->quoted_amount !== null ? (float) $item->quoted_amount : null,
            ])->values(),
            'suppliers' => $mrf->priceComparisons->map(fn ($row) => [
                'id' => $row->id,
                'vendorId' => $row->vendor?->vendor_id,
                'vendorName' => $row->vendor?->name,
                'itemDescription' => $row->item_description,
                'unitPrice' => (float) $row->unit_price,
                'quantity' => (float) $row->quantity,
                'totalPrice' => (float) $row->total_price,
                'isSelected' => (bool) $row->is_selected,
                'selectionReason' => $row->selection_reason,
            ])->values(),
            'paymentMilestones' => $paymentMilestones,
            'poGeneratedAt' => $mrf->po_generated_at?->toIso8601String(),
            'unsignedPoUrl' => $mrf->freshUnsignedPoStreamUrl() ?? $mrf->unsigned_po_url,
            'signedPoUrl' => $mrf->signed_po_url,
        ]);
    }

    /**
     * Fast-create a PO-backed MRF shell (no PDF, no notifications).
     *
     * @param  array<string, mixed>  $validated
     */
    public function createDraft(User $user, array $validated): MRF
    {
        return DB::transaction(function () use ($user, $validated) {
            $mrfId = MRF::generateMRFId($validated['contractType'] ?? 'emerald');
            $formattedId = $this->formattedIdGenerator->generate('MRF', [
                'contract_type' => $validated['contractType'] ?? 'emerald',
                'department' => $validated['department'] ?? $user->department,
                'category' => $validated['category'] ?? null,
                'created_at' => now(),
            ]);

            $mrf = MRF::create([
                'mrf_id' => $mrfId,
                'formatted_id' => $formattedId,
                'title' => $validated['title'],
                'category' => $validated['category'],
                'contract_type' => $validated['contractType'] ?? 'emerald',
                'urgency' => $validated['urgency'] ?? 'Medium',
                'description' => $validated['description'] ?? $validated['title'],
                'quantity' => $validated['quantity'] ?? '1',
                'estimated_cost' => $validated['estimatedCost'] ?? null,
                'currency' => PurchaseOrderCurrency::normalize($validated['currency'] ?? null),
                'justification' => $validated['justification']
                    ?? 'Manual PO created without RFQ — vendor and pricing captured directly on the purchase order.',
                'requester_id' => $user->id,
                'requester_name' => $user->name,
                'department' => $validated['department'] ?? $user->department,
                'date' => now(),
                'status' => 'procurement',
                'current_stage' => 'procurement',
                'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
                'source' => 'po_generated',
                'is_po_linked' => true,
                'po_draft_saved_at' => now(),
                'tax_rate' => $validated['taxRate'] ?? 0,
                'tax_amount' => $validated['taxAmount'] ?? 0,
                'ship_to_address' => $validated['shipToAddress'] ?? null,
                'procurement_manager_id' => $user->id,
            ]);

            $mrf->update(['linked_po_id' => $mrf->mrf_id]);

            if (! empty($validated['items'])) {
                $this->lineItemBudgetService->syncMrfItems($mrf, $validated['items']);
            }

            return $mrf->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateDraft(MRF $mrf, array $validated): MRF
    {
        $update = [];

        foreach ([
            'title' => 'title',
            'category' => 'category',
            'description' => 'description',
            'quantity' => 'quantity',
            'department' => 'department',
            'shipToAddress' => 'ship_to_address',
            'customTerms' => 'custom_terms',
            'poTermsMode' => 'po_terms_mode',
            'poSpecialTerms' => 'po_special_terms',
            'invoiceSubmissionEmail' => 'invoice_submission_email',
            'invoiceSubmissionCc' => 'invoice_submission_cc',
            'poNumber' => 'po_number',
        ] as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $update[$column] = $validated[$input];
            }
        }

        if (array_key_exists('estimatedCost', $validated)) {
            $update['estimated_cost'] = $validated['estimatedCost'];
        }

        if (array_key_exists('currency', $validated)) {
            $update['currency'] = PurchaseOrderCurrency::normalize($validated['currency']);
        }

        if (array_key_exists('taxRate', $validated)) {
            $update['tax_rate'] = $validated['taxRate'];
        }

        if (array_key_exists('taxAmount', $validated)) {
            $update['tax_amount'] = $validated['taxAmount'];
        }

        if (array_key_exists('selectedVendorId', $validated) && filled($validated['selectedVendorId'])) {
            $vendor = Vendor::query()->where('vendor_id', $validated['selectedVendorId'])->first();
            if ($vendor) {
                $update['selected_vendor_id'] = $vendor->id;
            }
        }

        if ($update !== []) {
            $update['po_draft_saved_at'] = now();
            $mrf->update($update);
        }

        if (! empty($validated['items'])) {
            $this->lineItemBudgetService->syncMrfItems($mrf, $validated['items']);
        }

        return $mrf->fresh();
    }
}
