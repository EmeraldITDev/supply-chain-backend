<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Controllers\Api\V1\Logistics\ApiController;
use App\Models\Logistics\Trip;
use Illuminate\Http\Request;

class PoPayloadController extends ApiController
{
    public function show(Request $request, int $id)
    {
        $trip = Trip::with(['vendor', 'vehicle', 'driver', 'rfqs.quotations'])->find($id);
        if (! $trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $serviceFrom = $trip->scheduled_departure_at ? $trip->scheduled_departure_at->toDateString() : null;
        $serviceTo = $trip->scheduled_arrival_at ? $trip->scheduled_arrival_at->toDateString() : $serviceFrom;

        $lineItems = [];

        // If RFQs/quotations exist, build line items from them (best-effort)
        $rfqs = $trip->rfqs ?? collect();
        if ($rfqs->isNotEmpty()) {
            foreach ($rfqs as $rfq) {
                $quot = $rfq->quotations?->sortByDesc('created_at')->first();
                $lineItems[] = [
                    'description' => $rfq->details['description'] ?? ($trip->title ?? "Transport service - {$trip->origin} to {$trip->destination}"),
                    'vendor_id' => $rfq->vendor_id ?? null,
                    'vendor_name' => $rfq->vendor?->name ?? null,
                    'unit_price' => $quot?->quoted_price ?? 0,
                    'quantity' => 1,
                    'total' => $quot?->quoted_price ?? 0,
                    'currency' => $quot?->currency ?? 'NGN',
                    'rfq_id' => $rfq->id,
                    'document_url' => $quot?->document_url ?? $rfq->document_url ?? null,
                ];
            }
        }

        if (empty($lineItems)) {
            $lineItems[] = [
                'description' => $trip->title ?? "Transport service - {$trip->origin} to {$trip->destination}",
                'vendor_id' => null,
                'vendor_name' => null,
                'unit_price' => 0,
                'quantity' => 1,
                'total' => 0,
                'currency' => 'NGN',
                'rfq_id' => null,
                'document_url' => null,
            ];
        }

        $total = array_sum(array_map(fn($li) => $li['total'], $lineItems));

        $payload = [
            'trip_reference' => $trip->trip_code,
            'trip_id' => $trip->id,
            'destination' => $trip->destination,
            'service_period' => [
                'from' => $serviceFrom,
                'to' => $serviceTo,
            ],
            'line_items' => $lineItems,
            'total_amount' => $total,
            'currency' => 'NGN',
            'supporting_docs' => [],
        ];

        return $this->success(['data' => $payload]);
    }
}
