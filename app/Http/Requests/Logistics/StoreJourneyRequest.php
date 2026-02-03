<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreJourneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => 'required|exists:logistics_trips,id',
            'status' => 'nullable|in:not_started,departed,en_route,arrived,closed',
            'departed_at' => 'nullable|date',
            'arrived_at' => 'nullable|date',
            'last_checkpoint_at' => 'nullable|date',
            'last_checkpoint_location' => 'nullable|string|max:255',
            'vendor_status' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
        ];
    }
}
