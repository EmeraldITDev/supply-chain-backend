<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;
use App\Models\User;
use App\Notifications\LogisticsEventNotification;
use Illuminate\Support\Facades\Log;

class TripSchedulingNotificationService
{
    public function notifyTripCreated(Trip $trip): void
    {
        $passengerIds = $this->normalizePassengerIds($trip->passenger_user_ids ?? []);
        $this->notifyPassengerUsers(
            $trip,
            $passengerIds,
            'trip_passenger_assigned',
            'Added to scheduled trip',
            $this->passengerAssignedMessage($trip)
        );

        $this->notifyAssignedDriver($trip, 'trip_driver_assigned', 'Assigned as trip driver', $this->driverAssignedMessage($trip));
    }

    /**
     * @param  list<int>  $previousIds
     * @param  list<int>  $newIds
     */
    public function notifyPassengerListChanged(Trip $trip, array $previousIds, array $newIds): void
    {
        $previousIds = $this->normalizePassengerIds($previousIds);
        $newIds = $this->normalizePassengerIds($newIds);

        $added = array_values(array_diff($newIds, $previousIds));
        $removed = array_values(array_diff($previousIds, $newIds));

        if ($added === [] && $removed === []) {
            return;
        }

        $this->notifyPassengerUsers(
            $trip,
            $added,
            'trip_passenger_added',
            'Added to scheduled trip',
            $this->passengerAssignedMessage($trip)
        );

        $this->notifyPassengerUsers(
            $trip,
            $removed,
            'trip_passenger_removed',
            'Removed from scheduled trip',
            sprintf(
                'You have been removed from trip %s (%s → %s).',
                $trip->trip_code,
                $trip->origin ?? '—',
                $trip->destination ?? '—'
            )
        );
    }

    public function notifyDriverReassignment(
        Trip $trip,
        ?int $previousDriverUserId,
        ?array $previousExternalDriver,
        ?int $newDriverUserId,
        ?array $newExternalDriver,
    ): void {
        $previousInternal = $previousDriverUserId ? (int) $previousDriverUserId : null;
        $newInternal = $newDriverUserId ? (int) $newDriverUserId : null;
        $previousExternal = $this->normalizedExternalDriver($previousExternalDriver);
        $newExternal = $this->normalizedExternalDriver($newExternalDriver);

        if ($previousInternal === $newInternal && $previousExternal === $newExternal) {
            return;
        }

        if ($previousInternal && $previousInternal !== $newInternal) {
            $this->notifyInternalDriverUser(
                $previousInternal,
                $trip,
                'trip_driver_unassigned',
                'Trip driver assignment removed',
                sprintf('You are no longer assigned as driver for trip %s.', $trip->trip_code)
            );
        }

        if ($newInternal && $newInternal !== $previousInternal) {
            $this->notifyInternalDriverUser(
                $newInternal,
                $trip,
                'trip_driver_assigned',
                'Assigned as trip driver',
                $this->driverAssignedMessage($trip)
            );
        } elseif ($newExternal && $newExternal !== $previousExternal) {
            Log::info('Trip external driver assigned (no in-app recipient)', [
                'trip_id' => $trip->id,
                'driver_name' => $newExternal['name'] ?? null,
            ]);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function notifyPassengerUsers(Trip $trip, array $userIds, string $type, string $title, string $message): void
    {
        if ($userIds === []) {
            return;
        }

        User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->each(fn (User $user) => $this->notifyUser($user, $trip, $type, $title, $message));
    }

    private function notifyAssignedDriver(Trip $trip, string $type, string $title, string $message): void
    {
        if ($trip->driver_user_id) {
            $this->notifyInternalDriverUser((int) $trip->driver_user_id, $trip, $type, $title, $message);
        }
    }

    private function notifyInternalDriverUser(int $userId, Trip $trip, string $type, string $title, string $message): void
    {
        $user = User::query()->find($userId);
        if ($user) {
            $this->notifyUser($user, $trip, $type, $title, $message);
        }
    }

    private function notifyUser(User $user, Trip $trip, string $type, string $title, string $message): void
    {
        try {
            $user->notifyNow(new LogisticsEventNotification($type, [
                'title' => $title,
                'message' => $message,
                'action_url' => '/logistics/trips/'.$trip->id,
                'trip_id' => $trip->id,
                'trip_code' => $trip->trip_code,
                'icon' => 'truck',
                'color' => 'blue',
                'priority' => 'normal',
            ]));
        } catch (\Throwable $e) {
            Log::warning('Trip scheduling notification failed', [
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function passengerAssignedMessage(Trip $trip): string
    {
        $departure = $trip->scheduled_departure_at?->format('M j, Y g:i A');

        return sprintf(
            'You have been scheduled on trip %s from %s to %s%s.',
            $trip->trip_code,
            $trip->origin ?? '—',
            $trip->destination ?? '—',
            $departure ? ' departing '.$departure : ''
        );
    }

    private function driverAssignedMessage(Trip $trip): string
    {
        $departure = $trip->scheduled_departure_at?->format('M j, Y g:i A');

        return sprintf(
            'You have been assigned as driver for trip %s (%s → %s)%s.',
            $trip->trip_code,
            $trip->origin ?? '—',
            $trip->destination ?? '—',
            $departure ? ' departing '.$departure : ''
        );
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private function normalizePassengerIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  array<string, mixed>|null  $driver
     * @return array{name: string, phone: string, license_number: ?string}|null
     */
    private function normalizedExternalDriver(?array $driver): ?array
    {
        if (! is_array($driver)) {
            return null;
        }

        $name = trim((string) ($driver['name'] ?? ''));
        $phone = trim((string) ($driver['phone'] ?? ''));
        if ($name === '' && $phone === '') {
            return null;
        }

        $license = trim((string) ($driver['license_number'] ?? $driver['licenseNumber'] ?? ''));

        return [
            'name' => $name,
            'phone' => $phone,
            'license_number' => $license !== '' ? $license : null,
        ];
    }
}
