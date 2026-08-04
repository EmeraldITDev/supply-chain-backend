<?php

namespace App\Services;

use App\Models\Logistics\Trip;
use App\Models\Logistics\TripRfq;
use App\Models\User;
use App\Services\WorkflowNotificationService;
use Illuminate\Support\Facades\Auth;

class TripRfqService
{
    public function dispatchRfqsForTrip(Trip $trip): void
    {
        $rfqsNeeded = [];

        if (! empty($trip->vendor_id)) {
            $rfqsNeeded[] = [
                'vendor_id' => $trip->vendor_id,
                'service_type' => 'transport',
                'details' => $this->buildTransportRfqDetails($trip),
            ];
        }

        if (! empty($trip->accommodation_vendor_id)) {
            $rfqsNeeded[] = [
                'vendor_id' => $trip->accommodation_vendor_id,
                'service_type' => 'accommodation',
                'details' => $this->buildAccommodationRfqDetails($trip),
            ];
        }

        if (! empty($trip->escort_vendor_id)) {
            $rfqsNeeded[] = [
                'vendor_id' => $trip->escort_vendor_id,
                'service_type' => 'escort',
                'details' => $this->buildEscortRfqDetails($trip),
            ];
        }

        foreach ($rfqsNeeded as $rfq) {
            $this->createAndSendRfq($trip, $rfq);
        }

        if (! empty($rfqsNeeded)) {
            $trip->update(['status' => Trip::STATUS_RFQ_PENDING]);
        }
    }

    private function buildTransportRfqDetails(Trip $trip): array
    {
        return [
            'service' => 'Vehicle transport',
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'departure_date' => $trip->scheduled_departure_at?->format('d M Y'),
            'return_date' => $trip->scheduled_arrival_at?->format('d M Y'),
            'passenger_count' => $this->buildPassengerCount($trip),
            'notes' => $trip->logistics_notes,
        ];
    }

    private function buildAccommodationRfqDetails(Trip $trip): array
    {
        return [
            'service' => 'Accommodation',
            'location' => $trip->accommodation_address ?? $trip->destination,
            'check_in_date' => $trip->scheduled_departure_at?->format('d M Y'),
            'check_out_date' => $trip->scheduled_arrival_at?->format('d M Y'),
            'number_of_rooms' => max(1, (int) ceil($this->buildPassengerCount($trip) / 2)),
            'preferred_hotel' => $trip->accommodation_hotel_name,
            'notes' => $trip->accommodation_notes,
        ];
    }

    private function buildEscortRfqDetails(Trip $trip): array
    {
        return [
            'service' => 'Escort / security service',
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'departure_date' => $trip->scheduled_departure_at?->format('d M Y'),
            'return_date' => $trip->scheduled_arrival_at?->format('d M Y'),
            'escort_type' => $trip->escort_type,
            'number_of_guards' => $trip->escort_personnel_count ?? 1,
            'description' => $trip->escort_description,
        ];
    }

    private function createAndSendRfq(Trip $trip, array $rfqData): void
    {
        $rfq = TripRfq::create([
            'trip_id' => $trip->id,
            'vendor_id' => $rfqData['vendor_id'],
            'service_type' => $rfqData['service_type'],
            'details' => $rfqData['details'],
            'status' => 'sent',
            'sent_at' => now(),
            'created_by' => Auth::id(),
        ]);

        app(WorkflowNotificationService::class)->notifyVendorRfqSent($rfq, $trip);
    }

    private function passengerCount(Trip $trip): int
    {
        return (int) ($trip->passenger_user_ids ? count($trip->passenger_user_ids) : 0);
    }

    private function buildPassengerCount(Trip $trip): int
    {
        return $this->passengerCount($trip);
    }
}
