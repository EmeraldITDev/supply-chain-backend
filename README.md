# Emerald Supply Chain — Backend API

Laravel REST API for Emerald's Supply Chain Management (SCM) platform: procurement, vendors, logistics, warehouse, reporting and the Finance AP integration.

Frontend: [`emerald-supply-chain`](../../Frontend/emerald-supply-chain) (React).

## Tech stack

PHP 8.2 · Laravel 11 · Sanctum (token auth) · PostgreSQL · AWS S3 (documents) · Resend (email)

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve        # http://localhost:8000
```

`composer setup` runs the same steps in one command.

### Key environment variables

| Variable | Description |
|---|---|
| `DB_*` | Database connection (PostgreSQL in production) |
| `APP_URL` / `FRONTEND_URL` | API and frontend URLs (used for links and CORS) |
| `AWS_*` | S3 bucket for MRF, PO and vendor documents |
| `MAIL_*`, `RESEND_API_KEY` | Outgoing email |
| `PO_*` | Recipients for PO and invoice emails |
| `FINANCE_AP_*` | Finance AP integration: URL, shared keys, cutover date |
| `GEMINI_API_KEY` | AI assistant chat |

`.env.example` explains each variable. Never commit real keys.

## API overview

All routes are under `/api`. Protected routes need a header in the form `Authorization: Bearer {token}`, using the token from `POST /api/auth/login`.

| Area | Main routes |
|---|---|
| Auth | `/auth/login`, `/auth/logout`, `/auth/me` |
| Requisitions | `/mrfs`, `/srfs` |
| Sourcing | `/rfqs`, `/quotations`, `/mrfs/{id}/price-comparisons` |
| Purchase orders | `/mrfs/{id}/generate-po`, `/pos/{id}`, `/mrfs/{id}/grn/*` |
| Vendors | `/vendors`, `/vendors/register`, `/vendor-portal/*` |
| Logistics | `/trip-requests`, `/trips`, `/fleet/*`, `/journeys` |
| Warehouse | `/warehouse/*` |
| Dashboards and reports | `/dashboard/*`, `/reports/*` |
| Finance AP | `/mrfs/{id}/payment-schedule`, `/mrfs/{id}/finance-sync`, `/webhooks/finance-ap` |

Run `php artisan route:list --path=api` for the full list.

## Tests

```bash
composer test            # or: php artisan test
```

## Project structure

```
app/Http/Controllers/Api/   API controllers (logistics under V1/Logistics)
app/Services/               Business logic (workflows, PO, Finance AP, reports)
app/Console/Commands/       Artisan maintenance commands
database/migrations/        Schema history
docs/                       Schema, specs and integration docs
```

Further reading:
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md): database tables
- [docs/LOGISTICS_MODULE_OVERVIEW.md](docs/LOGISTICS_MODULE_OVERVIEW.md): logistics module
- [docs/FINANCE_AP_SIDE_SCM_INTEGRATION.md](docs/FINANCE_AP_SIDE_SCM_INTEGRATION.md): Finance AP integration
- [frontend_changes.md](frontend_changes.md): API changes the frontend needs to know about

## Deployment

Deployed on **Render** (`render.yaml`, `Dockerfile`). Each build runs `composer install` and `php artisan migrate --force`. Set secrets (database, `APP_KEY`, AWS, mail, Finance AP keys) in the Render dashboard.
