<?php

namespace App\Support;

final class DocumentDisplayPayload
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withCamelCaseAliases(array $payload): array
    {
        $aliases = [
            'reference_number' => 'referenceNumber',
            'date_issued' => 'dateIssued',
            'certification_statement' => 'certificationStatement',
            'certification_text' => 'certificationText',
            'linked_po' => 'linkedPo',
            'line_items' => 'lineItems',
            'total_amount' => 'totalAmount',
            'issued_by' => 'issuedBy',
            'approved_by' => 'approvedBy',
            'created_at' => 'createdAt',
            'updated_at' => 'updatedAt',
            'grn_number' => 'grnNumber',
            'delivery_location' => 'deliveryLocation',
            'authorised_signatories' => 'authorisedSignatories',
            'quantity_ordered' => 'quantityOrdered',
            'quantity_received_default' => 'quantityReceivedDefault',
            'unit_price' => 'unitPrice',
            'delivery_note_number' => 'deliveryNoteNumber',
            'carrier_name' => 'carrierName',
            'driver_number' => 'driverNumber',
            'vehicle_plate_number' => 'vehiclePlateNumber',
            'received_at' => 'receivedAt',
            'received_by' => 'receivedBy',
            'line_items_received' => 'lineItemsReceived',
            'contact_person' => 'contactPerson',
            'po_number' => 'poNumber',
            'departure_date' => 'departureDate',
            'return_date' => 'returnDate',
            'signature_url' => 'signatureUrl',
            'signed_at' => 'signedAt',
            'file_url' => 'fileUrl',
        ];

        foreach ($aliases as $snake => $camel) {
            if (array_key_exists($snake, $payload) && ! array_key_exists($camel, $payload)) {
                $payload[$camel] = $payload[$snake];
            }
        }

        return $payload;
    }

    public static function nullIfEmpty(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function nullifyEmptyStrings(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = self::nullifyEmptyStrings($value);
                continue;
            }

            $normalized[$key] = self::nullIfEmpty($value);
        }

        return $normalized;
    }
}
