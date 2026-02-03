<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class SendNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_key' => 'required|string|max:191',
            'type' => 'required|string|max:100',
            'payload' => 'required|array',
            'roles' => 'required|array|min:1',
            'roles.*' => 'string',
        ];
    }
}
