# Frontend Fix — Trip Detail Dynamic Import / Chunk Mismatch

**Applies to:** Lovable/React frontend (not this backend repo)  
**Symptom:** `error loading dynamically imported module: .../assets/TripRequestDetailPage-CvG3aBSi.js`

---

## Root cause

Production Vite builds emit hashed chunk filenames (e.g. `TripRequestDetailPage-CvG3aBSi.js`). This error usually means the browser requested a chunk hash that no longer exists — commonly caused by:

1. **Filename casing mismatch** between the lazy import string and the actual file on disk (Linux/production builds are case-sensitive).
2. **Stale CDN/browser cache** after a new deploy (HTML references a new hash, old chunk URL 404s).
3. **Mixed deploy artifacts** (index.html from build A, assets from build B).

---

## Required frontend checks

### 1. Verify lazy import path casing

Locate your React Router lazy route, e.g.:

```tsx
const TripRequestDetailPage = lazy(() => import('@/pages/TripRequestDetailPage'));
```

Ensure the path matches the **exact** on-disk filename:

| On disk | Import must be |
|---------|----------------|
| `TripRequestDetailPage.tsx` | `import('.../TripRequestDetailPage')` |
| `tripRequestDetailPage.tsx` | `import('.../tripRequestDetailPage')` |

**Do not** mix PascalCase file with camelCase import (or vice versa).

Search the frontend repo:

```bash
rg "TripRequestDetailPage|tripRequestDetailPage" src/
find src -iname "*triprequestdetail*"
```

Align every `lazy(() => import(...))`, barrel `export`, and router path to one canonical casing.

### 2. Prefer centralized route modules

Define trip routes in one file to avoid duplicate imports with different casing:

```tsx
// src/routes/tripRoutes.tsx
import { lazy } from 'react';

export const TripRequestDetailPage = lazy(
  () => import('@/pages/logistics/TripRequestDetailPage')
);
```

### 3. Clean rebuild & cache bust

After fixing imports:

```bash
rm -rf dist node_modules/.vite
npm run build
```

Redeploy **both** `index.html` and the full `assets/` folder together. Invalidate CDN cache if used.

### 4. Optional hardening

- Add a global `import.meta.glob` error boundary on lazy routes to prompt refresh on chunk load failure.
- Pin `build.rollupOptions.output.manualChunks` only if you understand the trade-offs; default Vite chunking is usually fine once casing is correct.

---

## Backend API alignment (trip detail data)

Use these endpoints from the detail page (IDs are `logistics_trips.id`):

| Record | Detail endpoint |
|--------|-----------------|
| Trip request (`TRQ-*`) | `GET /api/trip-requests/{id}` |
| Logistics trip (`TRIP-*`) | `GET /api/trips/{id}` |

Unified directory list: `GET /api/trips` — each row includes `recordType`, `displayStatus`, and `detailPath`.

Request changes (logistics manager):

```
POST /api/trips/{id}/request-changes
POST /api/trip-requests/{id}/request-changes   # alias
Body: { "reason": "Please update passenger list" }
```

---

## Checklist

- [ ] `TripRequestDetailPage` filename and all `import()` strings use identical casing
- [ ] No duplicate page components (`TripRequestDetail.tsx` vs `TripRequestDetailPage.tsx`)
- [ ] Router `path` opens the lazy component that matches the API `recordType`
- [ ] Fresh production build deployed atomically
- [ ] CDN/cache purged after deploy
