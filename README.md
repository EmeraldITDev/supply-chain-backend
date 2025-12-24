# Supply Chain Management - Backend API

Laravel backend API for the Emerald Supply Chain Management system.

## Setup

1. **Install Dependencies**
   ```bash
   composer install
   ```

2. **Configure Environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Configure Database**
   Edit `.env` file with your PostgreSQL credentials (same as HRIS):
   ```env
   DB_CONNECTION=pgsql
   DB_HOST=your-postgres-host
   DB_PORT=5432
   DB_DATABASE=your-database-name
   DB_USERNAME=your-username
   DB_PASSWORD=your-password
   ```

4. **Run Migrations**
   ```bash
   php artisan migrate
   ```

5. **Start Server**
   ```bash
   php artisan serve
   ```

## API Endpoints

### Authentication
- `POST /api/auth/login` - Login user
- `POST /api/auth/logout` - Logout user (requires auth)
- `GET /api/auth/me` - Get current user (requires auth)

### MRF (Material Requisition Forms)
- `GET /api/mrfs` - List all MRFs
- `GET /api/mrfs/{id}` - Get single MRF
- `POST /api/mrfs` - Create MRF
- `PUT /api/mrfs/{id}` - Update MRF
- `POST /api/mrfs/{id}/approve` - Approve MRF
- `POST /api/mrfs/{id}/reject` - Reject MRF
- `DELETE /api/mrfs/{id}` - Delete MRF

### SRF (Service Requisition Forms)
- `GET /api/srfs` - List all SRFs
- `POST /api/srfs` - Create SRF
- `PUT /api/srfs/{id}` - Update SRF

### RFQ (Request for Quotations)
- `GET /api/rfqs` - List all RFQs
- `POST /api/rfqs` - Create RFQ
- `PUT /api/rfqs/{id}` - Update RFQ

### Quotations
- `GET /api/quotations` - List all quotations
- `POST /api/quotations` - Submit quotation
- `POST /api/quotations/{id}/approve` - Approve quotation
- `POST /api/quotations/{id}/reject` - Reject quotation

### Vendors
- `GET /api/vendors` - List all vendors
- `GET /api/vendors/{id}` - Get vendor details
- `POST /api/vendors/register` - Register new vendor (public)
- `GET /api/vendors/registrations` - Get vendor registrations (procurement only)
- `POST /api/vendors/registrations/{id}/approve` - Approve vendor registration

## Authentication

All protected endpoints require a Bearer token in the Authorization header:
```
Authorization: Bearer {token}
```

## Deployment to Render

See `RENDER_DEPLOYMENT.md` in the frontend repository for complete deployment instructions.

Key environment variables needed:
- Database credentials (same as HRIS)
- `APP_KEY` (generate using `php artisan key:generate`)
- `APP_URL` (your Render service URL)
- CORS settings

## Development

The backend uses Laravel Sanctum for API authentication. Controllers are in `app/Http/Controllers/Api/`.

## Next Steps

1. Complete migrations for all entities (MRF, SRF, RFQ, Quotations, Vendors)
2. Implement all controller methods
3. Add validation and authorization
4. Set up CORS for frontend access
5. Deploy to Render
