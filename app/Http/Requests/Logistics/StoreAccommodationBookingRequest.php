<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => 'nullable|integer|exists:logistics_trips,id',
            'passenger_names' => 'required|array|min:1',
            'passenger_names.*' => 'required|string|max:100',
            'destination_state' => 'required|string|max:100',
            'destination_city' => 'required|string|max:100',
            'number_of_nights' => 'required|integer|min:1|max:365',
            'hotel_name' => 'required|string|max:255',
            'check_in_date' => 'required|date|after_or_equal:today',
        ];
    }

    public function messages(): array
    {
        return [
            'passenger_names.required' => 'At least one passenger name is required',
            'passenger_names.array' => 'Passenger names must be an array',
            'passenger_names.min' => 'At least one passenger name is required',
            'destination_state.required' => 'Destination state is required',
            'destination_city.required' => 'Destination city is required',
            'number_of_nights.required' => 'Number of nights is required',
            'number_of_nights.min' => 'At least 1 night is required',
            'hotel_name.required' => 'Hotel name is required',
            'check_in_date.required' => 'Check-in date is required',
            'check_in_date.after_or_equal' => 'Check-in date must be today or later',
        ];
    }
}
