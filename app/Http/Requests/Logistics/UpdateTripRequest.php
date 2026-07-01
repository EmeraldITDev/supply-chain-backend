<?php

namespace App\Http\Requests\Logistics;

use App\Support\ExternalDriverRequest;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        ExternalDriverRequest::mergeIntoRequest($this);

        if ($this->has('passengerUserIds') && ! $this->has('passenger_user_ids')) {
            $this->merge(['passenger_user_ids' => $this->input('passengerUserIds')]);
        }

        if ($this->has('driverUserId') && ! $this->has('driver_user_id')) {
            $this->merge(['driver_user_id' => $this->input('driverUserId')]);
        }

        if ($this->has('driver_user_id') || $this->has('driverUserId')) {
            if ($this->filled('driver_user_id') || $this->filled('driverUserId')) {
                $this->merge(['external_driver' => null, 'externalDriver' => null]);
            }
        } elseif ($this->has('external_driver') || $this->has('externalDriver')) {
            $external = ExternalDriverRequest::resolve($this);
            $this->merge([
                'external_driver' => $external,
                'externalDriver' => $external,
                'driver_user_id' => null,
                'driverUserId' => null,
            ]);
        }

        if (! $this->has('title') && $this->filled('purpose')) {
            $this->merge(['title' => $this->input('purpose')]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'purpose' => 'nullable|string|max:255',
            'trip_type' => 'nullable|in:personnel,material,mixed',
            'booking_scope' => 'nullable|in:within_state,out_of_state_local,international,outside_state',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'status' => 'sometimes|in:draft,scheduled,vendor_assigned,in_progress,completed,closed,cancelled',
            'scheduled_departure_at' => 'nullable|date',
            'scheduled_arrival_at' => 'nullable|date|after_or_equal:scheduled_departure_at',
            'actual_departure_at' => 'nullable|date',
            'actual_arrival_at' => 'nullable|date|after_or_equal:actual_departure_at',
            'origin' => 'sometimes|string|max:255',
            'destination' => 'sometimes|string|max:255',
            'vendor_id' => 'nullable|exists:vendors,id',
            'vehicle_id' => 'nullable|exists:logistics_vehicles,id',
            'confirm_vehicle_assignment_override' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'passenger_user_ids' => 'sometimes|array|min:1',
            'passenger_user_ids.*' => 'integer|exists:users,id',
            'passengerUserIds' => 'sometimes|array|min:1',
            'passengerUserIds.*' => 'integer|exists:users,id',
            'driver_user_id' => 'nullable|integer|exists:users,id',
            'driverUserId' => 'nullable|integer|exists:users,id',
            ...ExternalDriverRequest::validationRules(),
        ];
    }
}
