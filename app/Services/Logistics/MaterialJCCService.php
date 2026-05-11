<?php

namespace App\Services\Logistics;

use App\Models\Logistics\MaterialMovement;
use App\Models\Logistics\MaterialJCC;
use App\Models\Logistics\MaterialJCCLineItem;
use App\Models\User;

class MaterialJCCService
{
    public function __construct(
        private MaterialJCCReferenceNumberService $referenceService,
    ) {
    }

    /**
     * Create a new JCC for a material movement.
     */
    public function createJCC(MaterialMovement $material, int $issuedBy, array $data = []): MaterialJCC
    {
        // Check if JCC already exists
        if ($material->jcc) {
            return $material->jcc;
        }

        $lineItemsInput = $data['line_items'] ?? [];
        unset($data['line_items']);

        $defaults = [
            'material_movement_id' => $material->id,
            'vendor_id' => $material->vendor_id,
            'vendor_name' => $material->vendor_name ?? $material->vendor?->name,
            'issued_by' => $issuedBy,
            'issued_at' => now(),
            'status' => 'draft',
            'reference_number' => $this->referenceService->generateReferenceNumber(),
            'certification_text' => $data['certification_text'] ?? $this->defaultCertificationText($material),
            'condition_on_arrival' => $data['condition_on_arrival'] ?? 'good',
            'po_number' => $data['po_number'] ?? null,
        ];

        $jcc = MaterialJCC::create(array_merge($defaults, array_intersect_key($data, array_flip([
            'po_number', 'condition_on_arrival',
        ]))));

        // Add line items if provided
        if (!empty($lineItemsInput)) {
            foreach ($lineItemsInput as $idx => $row) {
                $jcc->lineItems()->create([
                    'serial_number' => $idx + 1,
                    'material_name' => $row['material_name'],
                    'quantity' => $row['quantity'],
                    'condition' => $row['condition'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                ]);
            }
        }

        return $jcc->fresh(['lineItems']);
    }

    /**
     * Generate default certification text for material JCC.
     */
    private function defaultCertificationText(MaterialMovement $material): string
    {
        $date = now()->format('d/m/Y');
        return <<<TEXT
This is to certify that the below-mentioned goods were delivered in accordance with the terms and conditions of the supply agreement. The materials were received at {$material->destination} on {$date} in the condition as stated below. The delivery and condition of the goods have been verified and accepted by the receiving party.
TEXT;
    }

    /**
     * Generate suggested prefill line items from material movement.
     */
    public function generatePrefillLineItems(MaterialMovement $material): array
    {
        return [
            [
                'serial_number' => 1,
                'material_name' => $material->material_name,
                'quantity' => $material->quantity,
                'condition' => $material->condition_of_goods?->value,
                'remarks' => '',
            ],
        ];
    }

    /**
     * Update a JCC (while in DRAFT status).
     */
    public function updateJCC(MaterialJCC $jcc, array $data): MaterialJCC
    {
        if (!$jcc->isDraft()) {
            throw new \Exception('Only draft JCCs can be updated');
        }

        $lineItemsInput = $data['line_items'] ?? [];
        unset($data['line_items']);

        // Update JCC header
        $updateData = array_intersect_key($data, array_flip([
            'po_number', 'condition_on_arrival', 'certification_text',
        ]));

        $jcc->update($updateData);

        // Update or create line items
        if (!empty($lineItemsInput)) {
            // Delete existing line items
            $jcc->lineItems()->delete();

            // Create new line items
            foreach ($lineItemsInput as $idx => $row) {
                $jcc->lineItems()->create([
                    'serial_number' => $idx + 1,
                    'material_name' => $row['material_name'],
                    'quantity' => $row['quantity'],
                    'condition' => $row['condition'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                ]);
            }
        }

        return $jcc->fresh(['lineItems']);
    }

    /**
     * Get JCC summary for API response.
     */
    public function getJCCSummary(MaterialJCC $jcc): array
    {
        return [
            'id' => $jcc->id,
            'reference_number' => $jcc->reference_number,
            'material_movement_id' => $jcc->material_movement_id,
            'vendor_id' => $jcc->vendor_id,
            'vendor_name' => $jcc->vendor_name,
            'po_number' => $jcc->po_number,
            'certification_text' => $jcc->certification_text,
            'condition_on_arrival' => $jcc->condition_on_arrival?->value,
            'status' => $jcc->status?->value,
            'issued_by' => [
                'id' => $jcc->issuedBy?->id,
                'name' => $jcc->issuedBy?->name,
                'email' => $jcc->issuedBy?->email,
            ],
            'issued_at' => $jcc->issued_at,
            'approved_by' => $jcc->approvedBy ? [
                'id' => $jcc->approvedBy->id,
                'name' => $jcc->approvedBy->name,
                'email' => $jcc->approvedBy->email,
            ] : null,
            'approved_at' => $jcc->approved_at,
            'line_items' => $jcc->lineItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'serial_number' => $item->serial_number,
                    'material_name' => $item->material_name,
                    'quantity' => $item->quantity,
                    'condition' => $item->condition,
                    'remarks' => $item->remarks,
                ];
            })->toArray(),
            'created_at' => $jcc->created_at,
            'updated_at' => $jcc->updated_at,
        ];
    }
}
