# Frontend Changes — International Trip Transport Mode

**Backend repo:** `supply-chain-backend`  
**Auth:** Bearer token (`Authorization: Bearer {token}`)  
**Base URL:** `/api`

This document describes the backend change for an optional **transport mode** field on **international** staff trip requests. Implement these updates in the Lovable/React frontend.

---

## Summary

Trip requests now support an optional `international_transport_mode` field. It applies **only** when the trip type (booking scope) is **International (Out of Nigeria)**.

| JSON key | Type | Allowed values |
|----------|------|----------------|
| `international_transport_mode` | `string \| null` | `'flight'`, `'road'`, or `null` |
| `internationalTransportMode` | `string \| null` | camelCase alias (accepted on write; both keys returned on read) |

---

## Payload specifications

### Create trip request — `POST /api/trip-requests`

Add the field to the request body when the user selects an international trip:

```json
{
  "destination": "Accra, Ghana",
  "purpose": "Regional conference",
  "origin": "Office",
  "scheduled_departure_at": "2026-08-01T08:00:00Z",
  "scheduled_arrival_at": "2026-08-05T18:00:00Z",
  "passenger_user_ids": [2, 5],
  "bookingScope": "international",
  "international_transport_mode": "flight"
}
```

| Field | Required | Notes |
|-------|----------|--------|
| `international_transport_mode` | **No** | Omit or send `null` if not applicable |
| Accepted values | — | `'flight'` \| `'road'` only |
| `bookingScope` | **Yes** | Must be `international` for transport mode to be stored |

**Aliases accepted on write:** `internationalTransportMode`, `booking_scope`, `tripType`, `trip_type` (for booking scope only).

**Validation errors (422):**

- Invalid value: `errors.international_transport_mode` — must be `flight` or `road`
- Non-international trip with mode set: `errors.international_transport_mode` — *"Transport mode is only allowed for international trips."*

### Update trip request — `PUT /api/trip-requests/{id}`

Same field rules as create. If the user changes trip type away from `international`, the backend clears `international_transport_mode` automatically.

### API responses — all trip request endpoints

`data.trip` (and list items under `data.trips`) now include:

```json
{
  "bookingScope": "international",
  "booking_scope": "international",
  "bookingScopeLabel": "International (Out of Nigeria)",
  "international_transport_mode": "flight",
  "internationalTransportMode": "flight"
}
```

When not applicable or unset:

```json
{
  "bookingScope": "within_state",
  "international_transport_mode": null,
  "internationalTransportMode": null
}
```

**Affected endpoints:**

- `GET /api/trip-requests`
- `GET /api/trip-requests/all`
- `GET /api/trip-requests/{id}`
- `POST /api/trip-requests`
- `PUT /api/trip-requests/{id}`

---

## Conditional UI logic

### When to show the Transport Mode field

Show the **Transport Mode** select **only** when the trip type evaluates to **International**:

```ts
const isInternational =
  bookingScope === 'international' ||
  tripType === 'international' ||
  tripTypeLabel === 'International (Out of Nigeria)';
```

Use the same value the form already sends as `bookingScope` / `tripType`. In this API, the UI label **"Trip type"** maps to `bookingScope` (`within_state`, `out_of_state_local`, `international`) — **not** logistics `trip_type` (`personnel` / `material` / `mixed`).

### Field behaviour

| Trip type selected | Transport Mode UI | Value sent to API |
|--------------------|-------------------|-------------------|
| International | Show select (Flight / Road) | `'flight'` or `'road'`, or omit/`null` |
| Within State / Out of State (Local) | **Hide** and clear local state | Do **not** send `international_transport_mode` |

When the user switches from International to another trip type, clear the transport mode in form state before submit.

### Suggested select options

| Label | Value |
|-------|-------|
| Flight | `flight` |
| Road | `road` |

Field is optional even for international trips unless product rules require it later.

---

## Component updates checklist

Use this checklist when implementing in Lovable:

- [ ] **TypeScript interfaces** — Add `international_transport_mode?: 'flight' | 'road' | null` and `internationalTransportMode?: 'flight' | 'road' | null` to trip request types (list item, detail, create/update payload).
- [ ] **Trip Request create form** — Conditionally render Transport Mode select when `bookingScope === 'international'`; include `international_transport_mode` in `POST /api/trip-requests` body.
- [ ] **Trip Request edit form** — Same conditional field on `PUT /api/trip-requests/{id}`; pre-fill from `internationalTransportMode` or `international_transport_mode` in the loaded trip.
- [ ] **Trip Details view** — Display transport mode when `international_transport_mode` is non-null (e.g. "Transport: Flight" / "Transport: Road"); hide the row for non-international trips or when value is `null`.
- [ ] **Trip list cards** (optional) — Show a short badge or subtitle for international trips with a mode set, if useful for scanning.

---

## Important naming note

| UI concept | API field | Example values |
|------------|-----------|----------------|
| Trip type (domestic / international) | `bookingScope`, `tripType` | `within_state`, `out_of_state_local`, `international` |
| Logistics cargo type | `trip_type` (internal) | `personnel`, `material`, `mixed` — **do not use on staff trip request form** |
| International transport mode | `international_transport_mode` | `flight`, `road`, `null` |

---

## Backend deployment

Run migrations after pulling backend changes:

```bash
php artisan migrate
```

Migration adds nullable `international_transport_mode` to the `logistics_trips` table (trip requests are stored there with `TRQ-` trip codes).
