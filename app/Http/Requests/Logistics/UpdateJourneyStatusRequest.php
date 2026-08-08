<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJourneyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:not_started,scheduled,in_progress,departed,at_checkpoint,en_route,arrived,completed,closed,cancelled',
            'timestamp' => 'nullable|date',
            'location' => 'nullable|string|max:255',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Accept a few alternative frontend parameter names and normalise to `status`.
        $status = $this->input('status') ?? $this->input('new_status') ?? $this->input('journey_status') ?? $this->input('updateStatus') ?? $this->input('status_value');

        if ($status !== null && ! $this->has('status')) {
            $this->merge(['status' => $status]);
        }

        if (! $this->has('location')) {
            $location = $this->input('location')
                ?? $this->input('checkpoint_location')
                ?? $this->input('checkpoint_name')
                ?? $this->input('checkpointLocation');

            if ($location !== null) {
                $this->merge(['location' => $location]);
            }
        }
    }
}
