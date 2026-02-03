<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'maintenance_type' => 'required|string|max:100',
            'description' => 'nullable|string',
            'performed_at' => 'nullable|date',
            'next_due_at' => 'nullable|date',
            'cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
        ];
    }
}
