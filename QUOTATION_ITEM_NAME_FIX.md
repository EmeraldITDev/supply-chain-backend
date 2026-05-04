# Quotation Line Item Name Fix - Investigation & Solution

## Issue Summary
Vendor-submitted quotation line items were displaying as "Item" instead of their actual names in the RFQ management view.

## Root Cause
The issue was located in two places where quotation items are created:

### 1. **RFQWorkflowController::submitQuotation** (line ~765)
```php
// BEFORE (incorrect):
$itemName = $itemData['itemName'] ?? $itemData['name'] ?? 'Item';
```

### 2. **QuotationController::store** (line ~315)
```php
// BEFORE (incorrect):
$itemName = $itemData['itemName'] ?? $itemData['name'] ?? 'Item';
```

When vendors submitted quotations with items but didn't explicitly provide item names, the system defaulted to the literal string `'Item'`, which was then stored in the `quotation_items.item_name` field.

## Solution Applied
Both endpoints have been fixed to intelligently resolve item names with a proper fallback chain:

### 1. **RFQWorkflowController::submitQuotation** - FIXED ✓
```php
// AFTER (corrected):
$itemName = $itemData['itemName'] ?? $itemData['name'] ?? null;

// If item name not provided, try to get it from the linked RFQ item
$rfqItemId = $itemData['rfqItemId'] ?? $itemData['rfq_item_id'] ?? null;
if (!$itemName && $rfqItemId) {
    $rfqItem = RFQItem::find($rfqItemId);
    if ($rfqItem) {
        $itemName = $rfqItem->item_name;
    }
}

// Only use 'Item' as absolute fallback
if (!$itemName) {
    $itemName = 'Item';
}
```

### 2. **QuotationController::store** - FIXED ✓
```php
// AFTER (corrected):
$itemName = $itemData['itemName'] ?? $itemData['name'] ?? null;

// If item name not provided, try to get it from the linked RFQ item
$rfqItemId = $itemData['rfqItemId'] ?? $itemData['rfq_item_id'] ?? null;
if (!$itemName && $rfqItemId) {
    $rfqItem = \App\Models\RFQItem::find($rfqItemId);
    if ($rfqItem) {
        $itemName = $rfqItem->item_name;
    }
}

// Only use 'Item' as absolute fallback
if (!$itemName) {
    $itemName = 'Item';
}
```

## Fallback Chain
The item name resolution now follows this priority order:
1. **Explicit submission**: Use `itemName` or `name` from the request payload
2. **RFQ item lookup**: If no explicit name provided, fetch from linked `rfq_items` table using `rfq_item_id`
3. **Fallback**: Only use `'Item'` as absolute last resort

This ensures that if vendors don't explicitly provide names, the system uses the RFQ item's actual name instead of a generic placeholder.

## API Endpoints Affected

### **GET /rfqs/{id}/quotations** (RFQWorkflowController::getQuotationsForRFQ)
Returns quotation comparisons for RFQ management view.

**Items Response Structure:**
```json
{
  "success": true,
  "data": {
    "quotations": [
      {
        "quotation": {
          "id": "QT-000001",
          "total_amount": 50000.00,
          ...
        },
        "vendor": {
          "id": "V-001",
          "name": "Vendor Name",
          ...
        },
        "items": [
          {
            "id": 1,
            "rfq_item_id": 5,
            "item_name": "Laptop Computer",        // ← NOW PROPERLY POPULATED
            "name": "Laptop Computer",             // ← Alias for consistency
            "description": "Dell XPS 13",
            "quantity": 10,
            "unit": "units",
            "unit_price": 4500.00,
            "unitPrice": 4500.00,
            "total_price": 45000.00,
            "totalPrice": 45000.00,
            "specifications": "Intel i7, 16GB RAM"
          }
        ]
      }
    ],
    "rfq": {
      "id": "RFQ-001",
      "title": "Office Equipment – Procurement RFQ",
      "items": [
        {
          "id": 5,
          "item_name": "Laptop Computer",
          "description": "Dell XPS 13",
          "quantity": 10,
          "unit": "units",
          "specifications": "Intel i7, 16GB RAM"
        }
      ]
    }
  }
}
```

### **GET /quotations** (QuotationController::index)
Returns list of all quotations.

**Items Response Structure:**
```json
{
  "success": true,
  "data": [
    {
      "id": "QT-000001",
      "rfqId": "RFQ-001",
      "vendorId": "V-001",
      "vendorName": "Vendor Name",
      "totalAmount": 50000.00,
      "deliveryDays": 14,
      "items": [
        {
          "id": 1,
          "item_name": "Laptop Computer",        // ← NOW PROPERLY POPULATED
          "name": "Laptop Computer",             // ← Alias for consistency
          "description": "Dell XPS 13",
          "quantity": 10,
          "unit": "units",
          "unit_price": 4500.00,
          "unitPrice": 4500.00,
          "total_price": 45000.00,
          "totalPrice": 45000.00,
          "specifications": "Intel i7, 16GB RAM"
        }
      ]
    }
  ]
}
```

### **POST /rfqs/{id}/submit-quotation** (RFQWorkflowController::submitQuotation)
Vendor endpoint for submitting quotations.

**Request Payload (items):**
```json
{
  "items": [
    {
      "rfqItemId": 5,
      "itemName": "Laptop Computer",    // Optional - if provided, used directly
      "description": "Dell XPS 13",
      "quantity": 10,
      "unit": "units",
      "unitPrice": 4500.00,
      "specifications": "Intel i7, 16GB RAM"
    }
  ]
}
```

**Response Structure:**
```json
{
  "success": true,
  "data": {
    "id": "QT-000001",
    "quotation": {
      "id": "QT-000001",
      "items": [
        {
          "id": 1,
          "item_name": "Laptop Computer",        // ← NOW PROPERLY POPULATED
          "name": "Laptop Computer",             // ← From RFQ item if not explicitly provided
          "description": "Dell XPS 13",
          "quantity": 10,
          "unit": "units",
          "unit_price": 4500.00,
          "total_price": 45000.00,
          "specifications": "Intel i7, 16GB RAM"
        }
      ]
    }
  }
}
```

### **POST /quotations** (QuotationController::store)
Quotation submission endpoint.

**Request Payload (items):**
```json
{
  "items": [
    {
      "rfqItemId": 5,
      "itemName": "Laptop Computer",    // Optional - if provided, used directly
      "description": "Dell XPS 13",
      "quantity": 10,
      "unit": "units",
      "unitPrice": 4500.00,
      "specifications": "Intel i7, 16GB RAM"
    }
  ]
}
```

**Response Structure:**
```json
{
  "id": "QT-000001",
  "rfqId": "RFQ-001",
  "vendorId": "V-001",
  "vendorName": "Vendor Name",
  "totalAmount": 50000.00,
  "deliveryDate": "2026-05-18",
  "items": [
    {
      "id": 1,
      "itemName": "Laptop Computer",             // ← NOW PROPERLY POPULATED
      "description": "Dell XPS 13",
      "quantity": 10,
      "unit": "units",
      "unitPrice": 4500.00,
      "totalPrice": 45000.00,
      "specifications": "Intel i7, 16GB RAM"
    }
  ]
}
```

## Key Field Names for Frontend
The responses use both snake_case and camelCase for compatibility:

| Field | Formats | Type | Notes |
|-------|---------|------|-------|
| Item Name | `item_name` (snake_case) or `name` (alias) | String | Now populated from RFQ items if not explicitly provided |
| Description | `description` | String | Optional |
| Quantity | `quantity` | Integer | Required |
| Unit | `unit` | String | e.g., "units", "kg", "liter" |
| Unit Price | `unit_price` (snake_case) or `unitPrice` (camelCase) | Float | 2 decimal places |
| Total Price | `total_price` (snake_case) or `totalPrice` (camelCase) | Float | Calculated as quantity × unit_price |
| Specifications | `specifications` | String | Optional technical specs |
| RFQ Item ID | `rfq_item_id` (snake_case) or `rfqItemId` (camelCase) | Integer | Links to source RFQ item |

## Testing Recommendations
1. **Submit quotation without explicit item names**: Verify that item names are populated from RFQ items
2. **Submit quotation with explicit item names**: Verify that provided names take precedence
3. **Submit quotation items without rfq_item_id link**: Verify fallback to "Item" only when necessary
4. **RFQ management view**: Verify quotation items display actual names instead of "Item"
5. **Quotation list view**: Verify items show proper names across all vendor quotations

## Deployment Notes
- **Database**: No migrations needed - existing data remains in `quotation_items.item_name` field
- **Backward Compatibility**: Fully maintained - existing APIs continue to work
- **API Response Format**: Unchanged - only data values are corrected going forward
- **Frontend Impact**: Frontend no longer needs to provide default "Item" text - backend now handles this intelligently
