# Frontend Changes — Finance AP Implementation

Living document for API changes requiring frontend updates. See `FINANCE_AP_IMPLEMENTATION_PLAN.md`.

---

## Phase 0 — Pre-implementation foundations

### MRF responses now include `scmTransactionId`

**Affected endpoints (additive fields only):**

- `GET /api/mrfs`
- `GET /api/mrfs/{id}`
- `GET /api/mrfs/{id}/full-details`
- `GET /api/mrfs/{id}/progress-tracker`
- `POST /api/mrfs` (create response)

**New fields:**

```json
{
  "scmTransactionId": "550e8400-e29b-41d4-a716-446655440000",
  "scm_transaction_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Frontend action:** Add to TypeScript MRF types. Display only if needed for admin/debug; primary use is future Finance AP correlation (do not use for routing logic in UI).

---

### `GET /api/mrfs/{id}/procurement-documents`

**Query parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `type` | string optional | Filter: `vendor_invoice`, `grn`, `waybill`, `jcc`, `pfi`, `po_pdf`, `signed_po`, `delivery_confirmation`, `other` |
| `include_inactive` | boolean optional | Default false; set true to include superseded versions |

**Response:**

```json
{
  "success": true,
  "data": {
    "mrfId": "MRF-EMERALD-2026-001",
    "scmTransactionId": "uuid",
    "documents": [
      {
        "id": 1,
        "mrfId": 12,
        "vendorId": 3,
        "type": "signed_po",
        "fileName": "po_signed.pdf",
        "filePath": "procurement-documents/2026/05/...",
        "fileUrl": "https://...",
        "uploadedBy": { "id": 1, "name": "Jane Doe" },
        "uploadedAt": "2026-05-31T12:00:00+00:00",
        "version": 1,
        "isActive": true
      }
    ]
  }
}
```

**Frontend action:** No UI required in Phase 0 unless an documents panel already exists — prepare types for Phase 2+ document upload flows.

---

## Phase 1+ (pending)

Document payment schedule, vendor invoice portal, delivery confirmation, and Finance sync endpoints here as they are implemented.
