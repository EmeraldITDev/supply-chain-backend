<?php

namespace App\Http\Requests\Procurement;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePriceComparisonsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('vendor_id', $row)) {
                $rows[$i]['vendor_id'] = trim((string) $row['vendor_id']);
            }
            if (isset($row['manual_vendor']) && is_array($row['manual_vendor'])) {
                $mv = $row['manual_vendor'];
                $rows[$i]['manual_vendor'] = [
                    'name' => isset($mv['name']) ? trim((string) $mv['name']) : '',
                    'email' => isset($mv['email']) ? trim((string) $mv['email']) : '',
                    'phone' => isset($mv['phone']) ? trim((string) $mv['phone']) : '',
                    'address' => isset($mv['address']) ? trim((string) $mv['address']) : '',
                    'contact_person' => isset($mv['contact_person']) ? trim((string) $mv['contact_person']) : '',
                    'contact_person_email' => isset($mv['contact_person_email']) ? trim((string) $mv['contact_person_email']) : '',
                ];
            }
        }

        $this->merge(['rows' => $rows]);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->scmRole(), ['procurement_manager', 'procurement', 'supply_chain_director', 'admin'], true);
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:2',
            'rows.*.vendor_id' => 'nullable|string|max:50',
            'rows.*.manual_vendor' => 'nullable|array',
            'rows.*.manual_vendor.name' => 'nullable|string|max:255',
            'rows.*.manual_vendor.email' => 'nullable|email|max:255',
            'rows.*.manual_vendor.phone' => 'nullable|string|max:50',
            'rows.*.manual_vendor.address' => 'nullable|string|max:500',
            'rows.*.manual_vendor.contact_person' => 'nullable|string|max:255',
            'rows.*.manual_vendor.contact_person_email' => 'nullable|email|max:255',
            'rows.*.item_description' => 'required|string|max:1000',
            'rows.*.unit_price' => 'required|numeric|min:0',
            'rows.*.quantity' => 'required|numeric|gt:0',
            'rows.*.is_selected' => 'nullable|boolean',
            'rows.*.selection_reason' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'At least two supplier rows are required.',
            'rows.min' => 'At least two supplier rows are required.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $rows = $this->input('rows', []);
            if (!is_array($rows)) {
                return;
            }

            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    $v->errors()->add('rows.'.$i, 'Invalid row payload.');
                    continue;
                }
                $vid = trim((string) ($row['vendor_id'] ?? ''));
                $manualName = trim((string) (($row['manual_vendor']['name'] ?? '')));
                if ($vid === '' && $manualName === '') {
                    $v->errors()->add(
                        'rows.'.$i,
                        'Each row needs either vendor_id (existing supplier code, e.g. V005) or manual_vendor.name for a supplier not yet in the directory.'
                    );
                    continue;
                }
                if ($vid !== '' && $manualName !== '') {
                    $v->errors()->add(
                        'rows.'.$i,
                        'Use either vendor_id or manual supplier fields — not both on the same row.'
                    );
                    continue;
                }
                if ($vid !== '' && ! Vendor::query()->where('vendor_id', $vid)->exists()) {
                    $v->errors()->add(
                        'rows.'.$i.'.vendor_id',
                        'No supplier exists with this code. Use manual_vendor to add a new supplier, or pick a valid vendor_id.'
                    );
                    continue;
                }

                if ($vid === '' && $manualName !== '') {
                    $email = trim((string) ($row['manual_vendor']['email'] ?? ''));
                    $phone = trim((string) ($row['manual_vendor']['phone'] ?? ''));

                    if ($email === '') {
                        $v->errors()->add('rows.'.$i.'.manual_vendor.email', 'Supplier email is required for manual vendors.');
                    } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $v->errors()->add('rows.'.$i.'.manual_vendor.email', 'Supplier email must be a valid email address.');
                    }

                    if ($phone === '') {
                        $v->errors()->add('rows.'.$i.'.manual_vendor.phone', 'Supplier phone is required for manual vendors.');
                    }
                }
            }

            $selectedCount = 0;
            foreach ($rows as $row) {
                if (is_array($row) && filter_var($row['is_selected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $selectedCount++;
                }
            }

            if ($selectedCount === 0) {
                $v->errors()->add('rows', 'Exactly one supplier row must be marked as selected.');
            } elseif ($selectedCount > 1) {
                $v->errors()->add('rows', 'Only one supplier row can be marked as selected.');
            }
        });
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Insufficient permissions to manage price comparisons.',
            'code' => 'FORBIDDEN',
        ], 403));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Validation failed',
            'errors' => $validator->errors(),
            'code' => 'VALIDATION_ERROR',
        ], 422));
    }
}
