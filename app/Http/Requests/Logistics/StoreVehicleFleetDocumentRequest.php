<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleFleetDocumentRequest extends FormRequest
{
    public const DOCUMENT_TYPES = [
        'INSURANCE_CERTIFICATE',
        'VEHICLE_LICENCE',
        'TRANSPORT_PERMIT',
        'ROAD_WORTHINESS_CERTIFICATE',
        'LOCAL_GOVERNMENT_PAPERS',
    ];

    /** @var array<string, string> */
    private const LABEL_TO_TYPE = [
        'insurance certificate' => 'INSURANCE_CERTIFICATE',
        'insurance' => 'INSURANCE_CERTIFICATE',
        'vehicle licence' => 'VEHICLE_LICENCE',
        'vehicle license' => 'VEHICLE_LICENCE',
        'transport permit' => 'TRANSPORT_PERMIT',
        'road worthiness certificate' => 'ROAD_WORTHINESS_CERTIFICATE',
        'road worthiness' => 'ROAD_WORTHINESS_CERTIFICATE',
        'local government papers' => 'LOCAL_GOVERNMENT_PAPERS',
        'local government' => 'LOCAL_GOVERNMENT_PAPERS',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['document', 'attachment', 'upload'] as $key) {
            if ($this->hasFile($key) && !$this->hasFile('file')) {
                $this->files->set('file', $this->file($key));
                break;
            }
        }

        $rawType = $this->input('document_type', $this->input('type', $this->input('documentType')));
        if (is_string($rawType) && trim($rawType) !== '') {
            $normalized = $this->normalizeDocumentType(trim($rawType));
            if ($normalized !== null) {
                $this->merge(['document_type' => $normalized]);
            }
        }

        $expiry = $this->input('expiry_date')
            ?? $this->input('expiryDate')
            ?? $this->input('expires_at')
            ?? $this->input('expiration_date');
        if ($expiry !== null && $expiry !== '') {
            $this->merge(['expiry_date' => $expiry]);
        }
    }

    public function rules(): array
    {
        return [
            'document_type' => 'required|string|in:' . implode(',', self::DOCUMENT_TYPES),
            'expiry_date' => 'nullable|date',
            'file' => 'required|file|max:10240',
            // Aliases consumed in prepareForValidation (kept so they pass validation when present)
            'type' => 'nullable|string|max:100',
            'documentType' => 'nullable|string|max:100',
            'document' => 'nullable|file|max:10240',
            'attachment' => 'nullable|file|max:10240',
            'upload' => 'nullable|file|max:10240',
            'expiryDate' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'expiration_date' => 'nullable|date',
        ];
    }

    private function normalizeDocumentType(string $type): ?string
    {
        if (in_array($type, self::DOCUMENT_TYPES, true)) {
            return $type;
        }

        $collapsed = strtolower(preg_replace('/\s+/', ' ', $type) ?? $type);
        if (isset(self::LABEL_TO_TYPE[$collapsed])) {
            return self::LABEL_TO_TYPE[$collapsed];
        }

        $underscored = strtoupper(str_replace([' ', '-'], ['_', '_'], $type));
        if (in_array($underscored, self::DOCUMENT_TYPES, true)) {
            return $underscored;
        }

        return null;
    }
}
