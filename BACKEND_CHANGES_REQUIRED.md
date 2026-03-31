# BACKEND CHANGES & VERIFICATIONS REQUIRED

**Document Version**: 1.0  
**Date**: March 31, 2026  
**Purpose**: Complete backend implementation and verification checklist for Emerald Supply Chain fixes

---

## CRITICAL CHANGES - MUST IMPLEMENT

### 1. USER MANAGEMENT - Role Assignment & Storage

#### 1.1 Verify Role Field in Users Table

**Database Schema Check**:
```sql
-- For Laravel (MySQL)
DESCRIBE users;
-- Expected columns: id, email, name, role, created_at, updated_at

-- Verify role column exists and accepts string values
SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME='users' AND COLUMN_NAME='role';
-- Expected: varchar(100) or similar

-- Check for existing roles
SELECT DISTINCT role FROM users;
-- Expected: Should see roles like 'procurement_manager', 'finance', 'executive', etc.
```

**Schema Creation (if missing)**:
```sql
ALTER TABLE users ADD COLUMN role VARCHAR(100) DEFAULT 'employee' AFTER email;
-- Add index for performance
CREATE INDEX idx_user_role ON users(role);
```

---

#### 1.2 User Creation Endpoint - POST /users

**Current Implementation Expected**:
```php
// Laravel Controller: UserController@store
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8',
        'role' => 'required|string|in:employee,procurement_manager,finance,executive,supply_chain_director,chairman,admin,logistics', // ADD THIS
        'department' => 'nullable|string',
        'is_admin' => 'nullable|boolean',
        'can_manage_users' => 'nullable|boolean',
    ]);

    // Hash password BEFORE saving
    $validated['password'] = bcrypt($validated['password']);

    $user = User::create($validated);

    return response()->json([
        'success' => true,
        'message' => 'User created successfully',
        'data' => $user
    ], 201);
}
```

**Changes Required**:
- ✅ Accept `role` field in request validation
- ✅ Store role in users table
- ✅ Return created user with role in response
- ✅ Validate role against allowed values list

**Valid Role Values**:
```
'employee'
'procurement_manager'
'finance'
'executive'
'supply_chain_director'
'chairman'
'admin'
'logistics'
```

**Test Endpoint**:
```bash
curl -X POST http://localhost:8000/api/users \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Procurement",
    "email": "john@test.com",
    "password": "SecurePass123!",
    "role": "procurement_manager",
    "department": "Procurement"
  }'

# Expected Response (201 Created):
{
  "success": true,
  "data": {
    "id": 15,
    "name": "John Procurement",
    "email": "john@test.com",
    "role": "procurement_manager",
    "department": "Procurement",
    "created_at": "2026-03-31T..."
  }
}
```

---

#### 1.3 Login Endpoint - POST /auth/login

**JWT Token Generation**:
```php
// In JWT payload, MUST include role
$payload = [
    'user_id' => $user->id,
    'email' => $user->email,
    'role' => $user->role,  // ← ADD THIS
    'name' => $user->name,
    'exp' => now()->addHours(24)->timestamp,
];

$token = JWTAuth::encode($payload);
```

**Expected Response**:
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 15,
      "email": "john@test.com",
      "name": "John Procurement",
      "role": "procurement_manager",
      "department": "Procurement"
    },
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expiresAt": "2026-04-01T10:30:00Z"
  }
}
```

**Verification Steps**:
1. Login with test user
2. Decode JWT: `atob(token.split('.')[1])`
3. Verify `role` field present in payload
4. Verify `role` value matches: 'procurement_manager'

---

#### 1.4 Get Current User Endpoint - GET /auth/me

**Implementation**:
```php
public function me(Request $request)
{
    $user = Auth::user();
    
    return response()->json([
        'success' => true,
        'data' => [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,  // ← MUST INCLUDE
            'department' => $user->department,
            'employeeId' => $user->employee_id,
        ]
    ]);
}
```

**Test Endpoint**:
```bash
curl -H "Authorization: Bearer {token}" \
  http://localhost:8000/api/auth/me

# Expected: User object with role field
```

---

#### 1.5 User Update Endpoint - PUT /users/{id}

**Implementation**:
```php
public function update(Request $request, User $user)
{
    $validated = $request->validate([
        'name' => 'sometimes|string',
        'email' => 'sometimes|email|unique:users,email,' . $user->id,
        'role' => 'sometimes|string|in:employee,procurement_manager,finance,executive,supply_chain_director,chairman,admin,logistics',
        'department' => 'nullable|string',
        'password' => 'sometimes|min:8',
    ]);

    if (isset($validated['password'])) {
        $validated['password'] = bcrypt($validated['password']);
    }

    $user->update($validated);

    return response()->json([
        'success' => true,
        'data' => $user
    ]);
}
```

---

### 2. VENDOR DOCUMENT DOWNLOAD - Implement Endpoint

#### 2.1 Create New Endpoint: GET /vendors/registrations/{id}/documents/{documentId}/download

**Purpose**: Download vendor registration documents from AWS S3

**Implementation**:
```php
// Route
Route::get('/vendors/registrations/{registrationId}/documents/{documentId}/download', 
    'VendorRegistrationController@downloadDocument');

// Controller: VendorRegistrationController
public function downloadDocument($registrationId, $documentId)
{
    // Find registration - verify user has permission
    $registration = VendorRegistration::findOrFail($registrationId);
    
    // Authorization check
    $user = Auth::user();
    if (!$user || !in_array($user->role, ['admin', 'procurement', 'procurement_manager', 'executive', 'supply_chain_director'])) {
        // Can also check if vendor is downloading their own registration
        if (!($user && $user->id == $registration->vendor_id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
    }
    
    // Find document
    $document = VendorRegistrationDocument::where('id', $documentId)
        ->where('vendor_registration_id', $registrationId)
        ->firstOrFail();
    
    // Get file from S3
    $filePath = $document->file_path; // e.g., 'vendors/registrations/1/document_5.pdf'
    
    try {
        $disk = Storage::disk('s3');
        
        // Check file exists
        if (!$disk->exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }
        
        // Get file content
        $fileContent = $disk->get($filePath);
        $fileName = $document->file_name || basename($filePath);
        
        // Return file download
        return response()->streamDownload(
            fn() => echo $fileContent,
            $fileName,
            [
                'Content-Type' => $disk->mimeType($filePath),
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            ]
        );
    } catch (Exception $e) {
        return response()->json([
            'error' => 'Failed to download document',
            'message' => $e->getMessage()
        ], 500);
    }
}
```

**Database Assumptions**:
- `vendor_registrations` table with: id, vendor_id, status, created_at
- `vendor_registration_documents` table with: id, vendor_registration_id, file_path, file_name

**Verification**:
```php
// Check tables exist
Schema::hasTable('vendor_registrations'); // true
Schema::hasTable('vendor_registration_documents'); // true

// Check columns exist
Schema::hasColumn('vendor_registration_documents', 'file_path');
Schema::hasColumn('vendor_registration_documents', 'file_name');
```

**Test Endpoint**:
```bash
# After uploading a vendor registration with documents
curl -H "Authorization: Bearer {token}" \
  http://localhost:8000/api/vendors/registrations/1/documents/5/download \
  --output document.pdf

# Expected: File downloads with correct filename and content
```

---

### 3. FILE ATTACHMENTS - AWS S3 Configuration Verification

#### 3.1 Environment Variables

**Required in `.env` file**:
```bash
AWS_ACCESS_KEY_ID=your_aws_access_key
AWS_SECRET_ACCESS_KEY=your_aws_secret_key
AWS_DEFAULT_REGION=us-east-1  # or your region
AWS_BUCKET=your-s3-bucket-name
AWS_URL=https://your-s3-bucket-name.s3.amazonaws.com

# Optional but recommended
AWS_USE_PATH_STYLE_ENDPOINT=true  # if using S3-compatible service
S3_ENDPOINT=https://s3.amazonaws.com  # Ensure correct S3 endpoint
```

**Verify Configuration**:
```bash
# SSH into server, run:
php artisan tinker
>>> config('filesystems.disks.s3')
# Should show: array with key, secret, region, bucket, url

>>> Storage::disk('s3')->exists('test-file.txt')
# Should return: true/false - confirms connection works
```

---

#### 3.2 IAM Permissions Required

**S3 Bucket IAM Policy**:
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::your-bucket-name",
                "arn:aws:s3:::your-bucket-name/*"
            ]
        }
    ]
}
```

**Verify**:
```bash
# Test upload to S3
php artisan tinker
>>> Storage::disk('s3')->put('test.txt', 'test content')
# Should return: true

>>> Storage::disk('s3')->get('test.txt')
# Should return: 'test content'
```

---

#### 3.3 CORS Configuration (if Direct Uploads from Browser)

**S3 Bucket CORS Settings**:
```json
[
    {
        "AllowedOrigins": [
            "https://yourdomain.com",
            "https://www.yourdomain.com",
            "http://localhost:5173"
        ],
        "AllowedMethods": [
            "GET",
            "PUT",
            "POST",
            "DELETE"
        ],
        "AllowedHeaders": [
            "*"
        ],
        "ExposeHeaders": [
            "ETag",
            "x-amz-version-id"
        ],
        "MaxAgeSeconds": 3000
    }
]
```

**Note**: Current frontend uses server-side uploads (FormData to Laravel), so CORS only needed if implementing direct browser-to-S3 uploads in future.

---

#### 3.4 File Upload Endpoints - Verification

**Current Endpoints Using S3**:

##### PO Upload: POST /mrfs/{id}/generate-po
```php
// Verify: File stored in S3 with correct naming
$path = "pos/{$mrf->id}/po_{$poNumber}.pdf";
Storage::disk('s3')->put($path, $file->getContent());
```

**Test**:
```bash
# Upload PO via API
curl -X POST http://localhost:8000/api/mrfs/1/generate-po \
  -H "Authorization: Bearer {token}" \
  -F "po_number=PO-2026-001" \
  -F "unsigned_po=@sample.pdf"

# Verify file in S3
aws s3 ls s3://your-bucket/pos/1/
# Should show: po_PO-2026-001.pdf
```

---

##### Signed PO Upload: POST /mrfs/{id}/upload-signed-po
```php
// Similar to PO upload
$path = "pos/{$mrf->id}/signed_po_{$timestamp}.pdf";
Storage::disk('s3')->put($path, $file->getContent());
```

---

##### GRN Upload: POST /mrfs/{id}/complete-grn
```php
// Store GRN document
$path = "grns/{$mrf->id}/grn_{$timestamp}.pdf";
Storage::disk('s3')->put($path, $file->getContent());
```

---

##### Vendor Documents: POST /vendors/register
```php
// Multi-file upload
foreach ($uploadedDocuments as $doc) {
    $path = "vendors/{$vendor->id}/documents/{$doc->type}_{$timestamp}.pdf";
    Storage::disk('s3')->put($path, $doc->getContent());
}
```

---

##### Quotation Attachments: POST /rfqs/{id}/submit-quotation
```php
// Store quotation attachments
foreach ($attachments as $attachment) {
    $path = "quotations/{$rfq->id}/{$timestamp}_{$attachment->getClientOriginalName()}";
    Storage::disk('s3')->put($path, $attachment->getContent());
}
```

---

#### 3.5 Download Endpoints - Verification

**PO Download: GET /mrfs/{id}/download-po**
```php
public function downloadPO($id, $poType = 'unsigned')
{
    $mrf = MRF::findOrFail($id);
    
    // Determine file path
    $filePath = $poType === 'signed' 
        ? $mrf->signed_po_path 
        : $mrf->unsigned_po_path;
    
    if (!Storage::disk('s3')->exists($filePath)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $fileContent = Storage::disk('s3')->get($filePath);
    return response()->streamDownload(
        fn() => echo $fileContent,
        'PO_' . $mrf->po_number . '.pdf',
        ['Content-Type' => 'application/pdf']
    );
}
```

**Test**:
```bash
curl -H "Authorization: Bearer {token}" \
  http://localhost:8000/api/mrfs/1/download-po \
  --output po.pdf

# Verify file downloads successfully
```

---

### 4. PERMISSIONS & ROLE-BASED ACCESS

#### 4.1 Verify Role-Based Endpoint Access

**Example: Supply Chain Approval (only supply_chain_director)**:
```php
// Route + Middleware
Route::post('/mrfs/{id}/approve-vendor-selection', 'MRFController@approveVendorSelection')
    ->middleware('auth:api')
    ->middleware('role:supply_chain_director');

// Middleware Implementation
public function handle($request, Closure $next, ...$roles)
{
    $user = Auth::user();
    if (!$user || !in_array($user->role, $roles)) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }
    return $next($request);
}
```

**Roles & Access Levels**:

| Role | Can Approve MRF | Can Review Vendor | Can Process Payment | Can Generate PO |
|------|---|---|---|---|
| employee | ❌ | ❌ | ❌ | ❌ |
| procurement_manager | ❌ | ✅ | ❌ | ✅ |
| supply_chain_director | ❌ | ✅ | ❌ | ❌ |
| finance | ❌ | ❌ | ✅ | ❌ |
| executive | ✅ | ✅ | ❌ | ❌ |
| chairman | ✅ | ✅ | ✅ | ❌ |
| admin | ✅ | ✅ | ✅ | ✅ |

**Verification**:
```bash
# Test unauthorized access
curl -X POST http://localhost:8000/api/mrfs/1/approve-vendor-selection \
  -H "Authorization: Bearer {employee_token}"

# Expected: 403 Forbidden
```

---

#### 4.2 Verify Available Actions Endpoint

**Endpoint: GET /mrfs/{id}/available-actions**

**Implementation**:
```php
public function availableActions($id)
{
    $mrf = MRF::findOrFail($id);
    $user = Auth::user();
    
    $actions = [
        'can_approve' => in_array($user->role, ['executive', 'chairman', 'admin']),
        'can_generate_po' => in_array($user->role, ['procurement_manager', 'admin']),
        'can_review_vendor' => in_array($user->role, ['procurement_manager', 'executive', 'chairman', 'admin']),
        'can_process_payment' => in_array($user->role, ['finance', 'chairman', 'admin']),
    ];
    
    return response()->json(['success' => true, 'data' => $actions]);
}
```

**Test**:
```bash
curl -H "Authorization: Bearer {token}" \
  http://localhost:8000/api/mrfs/1/available-actions

# Should return actions available to user's role
```

---

## VERIFICATION CHECKLIST

### Before Deployment

- [ ] **User Management**
  - [ ] `POST /users` accepts and stores `role` field
  - [ ] `POST /auth/login` returns `role` in JWT payload
  - [ ] `GET /auth/me` returns user with `role` field
  - [ ] `PUT /users/{id}` can update user `role`
  - [ ] Test creating user with each role value

- [ ] **Vendor Documents**
  - [ ] `GET /vendors/registrations/{id}/documents/{docId}/download` endpoint exists
  - [ ] Endpoint returns file with correct headers
  - [ ] Authorization check working (non-procurement blocked)
  - [ ] Test with PDF, DOC, DOCX files

- [ ] **File Uploads (AWS)**
  - [ ] AWS credentials configured in `.env`
  - [ ] AWS_BUCKET and AWS_REGION set correctly
  - [ ] IAM role has S3 permissions (PutObject, GetObject)
  - [ ] Test PO upload successful, file appears in S3
  - [ ] Test PO download works
  - [ ] Test vendor doc multi-file upload
  - [ ] Test quotation attachment upload

- [ ] **Permissions**
  - [ ] Role middleware working
  - [ ] Endpoints check user role
  - [ ] Unauthorized requests return 403
  - [ ] Test each role has correct access levels
  - [ ] Test available actions endpoint

---

### Database Migrations Needed

```php
// Create migration if not already present
php artisan make:migration add_role_to_users_table

// Migration file:
Schema::table('users', function (Blueprint $table) {
    $table->string('role')->default('employee')->after('email');
    $table->index('role');
});

// Run migration
php artisan migrate
```

---

### Vendor Registration Document Schema

```php
// Ensure vendor_registration_documents table has:
Schema::create('vendor_registration_documents', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('vendor_registration_id');
    $table->string('file_path');  // S3 path
    $table->string('file_name');  // Original filename
    $table->string('type');  // CERTIFICATE, TAX, etc.
    $table->timestamps();
    
    $table->foreign('vendor_registration_id')
        ->references('id')->on('vendor_registrations')
        ->onDelete('cascade');
});
```

---

## TESTING COMMANDS

### Test User Role Assignment
```bash
# Create user with procurement_manager role
curl -X POST http://localhost:8000/api/users \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test User",
    "email": "test@test.com",
    "password": "SecurePass123!",
    "role": "procurement_manager"
  }'

# Login as test user
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@test.com",
    "password": "SecurePass123!"
  }'

# Decode JWT and verify role:
# atob('token.split(".")[1]') should contain: "role":"procurement_manager"
```

### Test Vendor Document Download
```bash
# Upload vendor registration with docs
curl -X POST http://localhost:8000/api/vendors/register \
  -H "Authorization: Bearer {vendor_token}" \
  -F "companyName=Test Vendor" \
  -F "email=vendor@test.com" \
  -F "documents[]=@certificate.pdf" \
  -F "document_types[]=CERTIFICATE"

# Create note of vendor registration ID and document ID

# Download document
curl -H "Authorization: Bearer {procurement_token}" \
  http://localhost:8000/api/vendors/registrations/1/documents/1/download \
  --output downloaded.pdf
```

### Test File Upload to S3
```bash
# Upload PO
curl -X POST http://localhost:8000/api/mrfs/1/generate-po \
  -H "Authorization: Bearer {pm_token}" \
  -F "po_number=PO-001" \
  -F "unsigned_po=@sample.pdf"

# Verify in S3
aws s3 ls s3://your-bucket/pos/1/ --region us-east-1
```

---

## TROUBLESHOOTING

### Issue: Role Not Saved in Database
```sql
-- Check if role column exists
SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME='users' AND COLUMN_NAME='role';

-- If not, run migration
php artisan migrate
```

### Issue: JWT Doesn't Include Role
```php
// Check JWT generation code
// Should include: 'role' => $user->role
// Not: 'role' => Auth::user()->role (might be null)

// Verify in Laravel config/auth.php:
'guards' => [
    'api' => [
        'driver' => 'jwt',  // or 'sanctum'
        'provider' => 'users',
    ],
],
```

### Issue: S3 Upload Fails
```bash
# Test S3 connection
php artisan tinker
>>> Storage::disk('s3')->put('test.txt', 'content')
>>> Storage::disk('s3')->exists('test.txt')

# If fails, check:
# 1. AWS credentials in .env
# 2. IAM permissions include s3:PutObject
# 3. S3 bucket exists and is accessible
```

---

## DEPLOYMENT SEQUENCE

1. **Database**: Run migrations to add role column
2. **Environment**: Set AWS credentials in `.env`
3. **Backend**: Deploy code changes
4. **Testing**: Run test commands above
5. **Frontend**: Deploy frontend bundle
6. **Verification**: Test full workflow end-to-end

---

*This document is a comprehensive guide for backend implementation.*  
*All endpoints and configurations listed must be verified before production deployment.*

