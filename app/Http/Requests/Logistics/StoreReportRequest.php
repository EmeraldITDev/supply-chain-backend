<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => 'nullable|exists:logistics_trips,id',
            'journey_id' => 'nullable|exists:logistics_journeys,id',
            'report_type' => 'required|string|max:100',
            'payload' => 'nullable|array',
        ];
    }
}
