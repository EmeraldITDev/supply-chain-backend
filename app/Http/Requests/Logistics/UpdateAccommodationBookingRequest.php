<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccommodationBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => 'nullable|integer|exists:logistics_trips,id',
            'passenger_names' => 'nullable|array|min:1',
            'passenger_names.*' => 'nullable|string|max:100',
            'destination_state' => 'nullable|string|max:100',
            'destination_city' => 'nullable|string|max:100',
            'number_of_nights' => 'nullable|integer|min:1|max:365',
            'hotel_name' => 'nullable|string|max:255',
            'check_in_date' => 'nullable|date|after_or_equal:today',
        ];
    }
}
