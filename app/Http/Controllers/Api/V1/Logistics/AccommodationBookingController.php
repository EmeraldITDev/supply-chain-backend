<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreAccommodationBookingRequest;
use App\Http\Requests\Logistics\UpdateAccommodationBookingRequest;
use App\Models\Logistics\AccommodationBooking;
use App\Models\Logistics\Trip;
use App\Services\Logistics\AccommodationBookingService;
use Illuminate\Http\Request;

class AccommodationBookingController extends ApiController
{
    public function __construct(
        private AccommodationBookingService $bookingService,
    ) {
    }

    /**
     * Create a new accommodation booking
     */
    public function store(StoreAccommodationBookingRequest $request)
    {
        try {
            $data = $request->validated();
            $booking = $this->bookingService->createBooking($data, $request->user()->id);

            return $this->success([
                'message' => 'Accommodation booking created successfully',
                'booking' => $this->bookingService->getBookingSummary($booking),
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create booking: ' . $e->getMessage(), 'CREATION_FAILED', 400);
        }
    }

    /**
     * Get all accommodation bookings with optional filters
     */
    public function index(Request $request)
    {
        try {
            $bookings = $this->bookingService->getAllBookings(
                tripId: $request->input('trip_id'),
                destination: $request->input('destination'),
                dateFrom: $request->input('date_from'),
                dateTo: $request->input('date_to'),
                perPage: $request->input('per_page', 15)
            );

            return $this->success([
                'bookings' => $bookings->items(),
                'pagination' => [
                    'total' => $bookings->total(),
                    'per_page' => $bookings->perPage(),
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve bookings: ' . $e->getMessage(), 'FETCH_FAILED', 400);
        }
    }

    /**
     * Get a single accommodation booking
     */
    public function show(string $id)
    {
        $booking = AccommodationBooking::find($id);

        if (!$booking) {
            return $this->error('Booking not found', 'NOT_FOUND', 404);
        }

        return $this->success([
            'booking' => $this->bookingService->getBookingSummary($booking),
        ]);
    }

    /**
     * Update an accommodation booking
     */
    public function update(UpdateAccommodationBookingRequest $request, string $id)
    {
        $booking = AccommodationBooking::find($id);

        if (!$booking) {
            return $this->error('Booking not found', 'NOT_FOUND', 404);
        }

        try {
            $data = $request->validated();
            $booking = $this->bookingService->updateBooking($booking, $data, $request->user()->id);

            return $this->success([
                'message' => 'Booking updated successfully',
                'booking' => $this->bookingService->getBookingSummary($booking),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to update booking: ' . $e->getMessage(), 'UPDATE_FAILED', 400);
        }
    }

    /**
     * Delete (soft delete) an accommodation booking
     */
    public function destroy(string $id)
    {
        $booking = AccommodationBooking::find($id);

        if (!$booking) {
            return $this->error('Booking not found', 'NOT_FOUND', 404);
        }

        try {
            $this->bookingService->deleteBooking($booking);

            return $this->success([
                'message' => 'Booking deleted successfully',
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to delete booking: ' . $e->getMessage(), 'DELETE_FAILED', 400);
        }
    }

    /**
     * Get accommodations for a specific trip
     */
    public function getTripAccommodations(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        try {
            $accommodations = $this->bookingService->getTripAccommodations($trip);

            return $this->success([
                'trip_id' => $trip->id,
                'trip_code' => $trip->trip_code,
                'accommodations' => $accommodations,
                'total_accommodations' => count($accommodations),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve accommodations: ' . $e->getMessage(), 'FETCH_FAILED', 400);
        }
    }

    /**
     * Add a passenger to a booking
     */
    public function addPassenger(Request $request, string $id)
    {
        $booking = AccommodationBooking::find($id);

        if (!$booking) {
            return $this->error('Booking not found', 'NOT_FOUND', 404);
        }

        $request->validate([
            'passenger_name' => 'required|string|max:100',
        ]);

        try {
            $this->bookingService->addPassenger($booking, $request->input('passenger_name'));

            return $this->success([
                'message' => 'Passenger added successfully',
                'passenger_count' => $booking->getPassengerCount(),
                'passengers' => $booking->passenger_names,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to add passenger: ' . $e->getMessage(), 'ADD_FAILED', 400);
        }
    }

    /**
     * Remove a passenger from a booking
     */
    public function removePassenger(Request $request, string $id)
    {
        $booking = AccommodationBooking::find($id);

        if (!$booking) {
            return $this->error('Booking not found', 'NOT_FOUND', 404);
        }

        $request->validate([
            'passenger_name' => 'required|string',
        ]);

        try {
            $this->bookingService->removePassenger($booking, $request->input('passenger_name'));

            return $this->success([
                'message' => 'Passenger removed successfully',
                'passenger_count' => $booking->getPassengerCount(),
                'passengers' => $booking->passenger_names,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to remove passenger: ' . $e->getMessage(), 'REMOVE_FAILED', 400);
        }
    }
}
