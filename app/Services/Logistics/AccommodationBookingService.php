<?php

namespace App\Services\Logistics;

use App\Models\Logistics\AccommodationBooking;
use App\Models\Logistics\Trip;
use Illuminate\Pagination\Paginator;

class AccommodationBookingService
{
    /**
     * Create a new accommodation booking
     */
    public function createBooking(array $data, int $createdBy): AccommodationBooking
    {
        $data['created_by'] = $createdBy;

        $booking = AccommodationBooking::create($data);

        // Auto-calculate check_out_date
        if ($booking->check_in_date && $booking->number_of_nights) {
            $booking->check_out_date = $booking->check_in_date->addDays($booking->number_of_nights);
            $booking->saveQuietly();
        }

        return $booking;
    }

    /**
     * Update an accommodation booking
     */
    public function updateBooking(AccommodationBooking $booking, array $data, int $updatedBy): AccommodationBooking
    {
        $data['updated_by'] = $updatedBy;
        $booking->update($data);

        // Recalculate check_out_date if dates or nights changed
        if (
            isset($data['check_in_date']) || isset($data['number_of_nights'])
        ) {
            if ($booking->check_in_date && $booking->number_of_nights) {
                $booking->check_out_date = $booking->check_in_date->addDays($booking->number_of_nights);
                $booking->saveQuietly();
            }
        }

        return $booking;
    }

    /**
     * Delete an accommodation booking (soft delete)
     */
    public function deleteBooking(AccommodationBooking $booking): void
    {
        $booking->delete();
    }

    /**
     * Get all bookings with optional filters
     */
    public function getAllBookings(
        ?int $tripId = null,
        ?string $destination = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $perPage = 15
    ) {
        $query = AccommodationBooking::query();

        if ($tripId) {
            $query->where('trip_id', $tripId);
        }

        if ($destination) {
            $query->where('destination_city', 'like', "%{$destination}%")
                ->orWhere('destination_state', 'like', "%{$destination}%");
        }

        if ($dateFrom) {
            $query->whereDate('check_in_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('check_out_date', '<=', $dateTo);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get accommodations for a specific trip
     */
    public function getTripAccommodations(Trip $trip)
    {
        return $trip->accommodations()
            ->with('createdBy:id,name,email')
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'passenger_names' => $booking->passenger_names,
                    'destination_state' => $booking->destination_state,
                    'destination_city' => $booking->destination_city,
                    'number_of_nights' => $booking->number_of_nights,
                    'hotel_name' => $booking->hotel_name,
                    'check_in_date' => $booking->check_in_date->format('Y-m-d'),
                    'check_out_date' => $booking->check_out_date?->format('Y-m-d'),
                    'passenger_count' => $booking->getPassengerCount(),
                    'created_by' => $booking->createdBy?->name,
                    'created_at' => $booking->created_at,
                ];
            });
    }

    /**
     * Add a passenger to a booking
     */
    public function addPassenger(AccommodationBooking $booking, string $name): void
    {
        $booking->addPassenger($name);
    }

    /**
     * Remove a passenger from a booking
     */
    public function removePassenger(AccommodationBooking $booking, string $name): void
    {
        $booking->removePassenger($name);
    }

    /**
     * Get booking summary
     */
    public function getBookingSummary(AccommodationBooking $booking): array
    {
        return [
            'id' => $booking->id,
            'trip_id' => $booking->trip_id,
            'trip_code' => $booking->trip?->trip_code,
            'passengers' => $booking->passenger_names,
            'passenger_count' => $booking->getPassengerCount(),
            'destination' => "{$booking->destination_city}, {$booking->destination_state}",
            'hotel_name' => $booking->hotel_name,
            'check_in_date' => $booking->check_in_date->format('Y-m-d'),
            'check_out_date' => $booking->check_out_date?->format('Y-m-d'),
            'number_of_nights' => $booking->number_of_nights,
            'created_by' => $booking->createdBy?->name,
            'created_at' => $booking->created_at->format('Y-m-d H:i'),
            'updated_at' => $booking->updated_at->format('Y-m-d H:i'),
        ];
    }
}
