<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('material_code') || empty($this->input('material_code'))) {
            $this->merge([
                'material_code' => 'MAT-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'material_code' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trip_id' => 'nullable|exists:logistics_trips,id',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
        ];
    }
}
