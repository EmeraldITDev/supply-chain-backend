<?php

namespace App\Rules;

use App\Models\RFQItem;
use Illuminate\Contracts\Validation\Rule;

/**
 * Validation rule for quotation item names.
 * Ensures that:
 * 1. If itemName is provided, it must not be a generic placeholder like "Item"
 * 2. If itemName is not provided, rfq_item_id must be provided to resolve the name from RFQ
 */
class ValidQuotationItemName implements Rule
{
    private $rfqItemId;
    private $errorMessage;

    /**
     * Create a new rule instance.
     *
     * @param string|null $rfqItemId The RFQ item ID to validate against
     */
    public function __construct($rfqItemId = null)
    {
        $this->rfqItemId = $rfqItemId;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        // If item name is provided, ensure it's not a generic placeholder
        if (!empty($value)) {
            $lowerValue = strtolower(trim($value));

            // Reject generic/placeholder names
            if ($lowerValue === 'item' || $lowerValue === 'unnamed' || $lowerValue === 'product') {
                $this->errorMessage = 'Item name cannot be a generic placeholder. Please provide the actual item name or link to an RFQ item.';
                return false;
            }

            // Valid if a meaningful name was provided
            return true;
        }

        // If no item name provided, must have rfq_item_id to resolve from
        if (empty($this->rfqItemId)) {
            $this->errorMessage = 'Either provide an explicit item name or link to an RFQ item (rfq_item_id).';
            return false;
        }

        // Verify the RFQ item exists and has a valid name
        $rfqItem = RFQItem::find($this->rfqItemId);
        if (!$rfqItem || empty($rfqItem->item_name)) {
            $this->errorMessage = 'Linked RFQ item (ID: ' . $this->rfqItemId . ') not found or has no item name.';
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return $this->errorMessage ?? 'The item name is invalid.';
    }
}
