<?php

namespace App\Http\Controllers\Api;

use App\Enums\VendorCategory;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRating;
use App\Models\VendorRegistration;
use App\Models\VendorRegistrationDocument;
use App\Models\Quotation;
use App\Services\NotificationService;
use App\Services\VendorApprovalService;
use App\Services\VendorDocumentService;
use App\Services\QuotationAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VendorController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get available vendor categories
     * Public endpoint - no authentication required
     */
    public function categories()
    {
        return response()->json([
            'success' => true,
            'categories' => VendorCategory::values(),
        ]);
    }

    /**
     * Mask account number for display (show last 4 digits only).
     * Sensitive data: only authorized roles see full number; UI can show masked.
     */
    private function maskAccountNumber(?string $accountNumber): ?string
    {
        if ($accountNumber === null || $accountNumber === '') {
            return null;
        }
        $len = strlen($accountNumber);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($accountNumber, -4);
    }

    /**
     * Find vendor by ID (supports both primary key and vendor_id)
     *
     * @param mixed $id - Can be primary key (integer) or vendor_id (string like "V001")
     * @return Vendor|null
     */
    private function findVendor($id): ?Vendor
    {
        // Try to find by vendor_id first (business identifier like "V001")
        $vendor = Vendor::where('vendor_id', $id)->first();

        // If not found and $id is numeric, try by primary key
        if (!$vendor && is_numeric($id)) {
            $vendor = Vendor::find($id);
        }

        return $vendor;
    }

    /**
     * Get all vendors
     */
    public function index(Request $request)
    {
        $query = Vendor::query();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        $vendors = $query->orderBy('name')->get();

        return response()->json($vendors->map(function($vendor) {
            return [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
                'category' => $vendor->category,
                'rating' => $vendor->rating ? (float) $vendor->rating : 0,
                'totalOrders' => $vendor->total_orders,
                'status' => $vendor->status,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'address' => $vendor->address,
                'taxId' => $vendor->tax_id,
                'contactPerson' => $vendor->contact_person,
            ];
        }));
    }

    /**
     * Get vendor by ID
     */
    public function show($id)
    {
        $vendor = Vendor::where('vendor_id', $id)->firstOrFail();

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Load documents via the vendor registration since documents belong to registrations
        $registration = \App\Models\VendorRegistration::where('email', $vendor->email)
            ->latest()
            ->first();

        $documents = [];
        if ($registration && $registration->documents) {
            $documentService = app(\App\Services\VendorDocumentService::class);
            $isApproved = $registration->status === \App\Models\VendorRegistration::STATUS_APPROVED;

            foreach ($registration->documents as $doc) {
                $filePath = $doc['file_path'] ?? null;
                $freshUrl = null;

                if ($filePath) {
                    try {
                        $freshUrl = $documentService->getDocumentUrl(
                            $filePath,
                            $doc['id'] ?? null,
                            $registration->id,
                            $isApproved
                        );
                    } catch (\Exception $e) {
                        \Log::warning('Failed to regenerate document URL in show()', [
                            'file_path' => $filePath,
                            'error' => $e->getMessage()
                        ]);
                        $freshUrl = $doc['file_share_url'] ?? $doc['file_url'] ?? null;
                    }
                }

               $documents[] = [
                'file_name'      => $doc['file_name'] ?? null,
                'file_type'      => $doc['file_type'] ?? null,
                'file_path'      => $doc['file_path'] ?? null,
                'file_url'       => $freshUrl,
                'file_share_url' => $freshUrl,
                'uploaded_at'    => $doc['uploaded_at'] ?? null,
            ];
            }
        }

        // Fall back to the latest registration for profile fields that may not yet
        // have been persisted onto the vendor record (e.g. vendors approved before
        // the business-profile capture was wired up).
        $annualRevenue      = $vendor->annual_revenue      ?? $registration?->annual_revenue;
        $numberOfEmployees  = $vendor->number_of_employees ?? $registration?->number_of_employees;
        $yearEstablished    = $vendor->year_established    ?? $registration?->year_established;
        $website            = $vendor->website             ?? $registration?->website;

        return response()->json([
            'id'            => $vendor->vendor_id,
            'name'          => $vendor->name,
            'category'      => $vendor->category,
            'rating'        => $vendor->rating ? (float) $vendor->rating : 0,
            'totalOrders'   => $vendor->total_orders,
            'status'        => $vendor->status,
            'email'         => $vendor->email,
            'phone'         => $vendor->phone,
            'address'       => $vendor->address,
            'taxId'         => $vendor->tax_id,
            'contactPerson' => $vendor->contact_person,
            'notes'         => $vendor->notes,
            'documents'     => $documents,
            'annual_revenue'      => $annualRevenue,
            'annualRevenue'       => $annualRevenue,
            'number_of_employees' => $numberOfEmployees,
            'numberOfEmployees'   => $numberOfEmployees,
            'year_established'    => $yearEstablished,
            'yearEstablished'     => $yearEstablished,
            'website'             => $website,
            'created_at'          => $vendor->created_at,
        ]);
    }

    /**
     * Register new vendor (public endpoint)
     * Improved with comprehensive error handling and null checks
     */
    public function register(Request $request, VendorDocumentService $documentService)
    {
        // Start timer to detect timeouts
        $startTime = microtime(true);

        \Log::info('Raw registration request received', [
            'content_type' => $request->header('Content-Type'),
            'input_keys' => array_keys($request->all()),
            'file_keys' => array_keys($request->allFiles()),
            'email_direct' => $request->email,
            'email_input' => $request->input('email'),
        ]);

        $requestId = \Illuminate\Support\Str::uuid();

        // Log without sensitive fields (account_number)
        $safeKeys = array_diff(array_keys($request->all()), ['account_number']);
        \Log::info('Vendor registration attempt', [
            'request_id' => $requestId,
            'all_fields' => array_values($safeKeys),
            'email' => $request->email ?? $request->get('email'),
            'has_documents' => $request->hasFile('documents'),
            'timestamp' => now()->toIso8601String(),
        ]);

        try {
            // Verify database connection before proceeding
            try {
                \DB::connection()->getPdo();
                \Log::info('Database connection verified', ['request_id' => $requestId]);
            } catch (\Exception $dbError) {
                \Log::error('Database connection failed', [
                    'request_id' => $requestId,
                    'error' => $dbError->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Database connection error. Please try again later.',
                    'code' => 'DATABASE_ERROR'
                ], 503);
            }

            // Guard: Ensure request is a single object, not an array of registrations
            $allData = $request->all();
            if (is_array($allData) && isset($allData[0]) && is_array($allData[0])) {
                \Log::warning('Batch registration attempt received', [
                    'request_id' => $requestId,
                    'received_count' => count($allData)
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid request format: Batch registration not supported. Send one registration at a time.',
                    'code' => 'INVALID_REQUEST_FORMAT',
                    'expected' => 'Single object with companyName, email, category, etc.',
                    'received' => 'Array of objects'
                ], 422);
            }

            // Get and guard form fields - support both camelCase and snake_case
            $companyName = trim($request->input('companyName') ?? $request->input('company_name') ?? '');
            $category = trim($request->input('category') ?? '');
            $phone = trim($request->input('phone') ?? '');
            $address = trim($request->input('address') ?? '');
            $taxId = trim($request->input('taxId') ?? $request->input('tax_id') ?? '');
            $contactPerson = trim($request->input('contactPerson') ?? $request->input('contact_person') ?? '');

            // DEBUG: Log all possible ways to access email
            \Log::info('Email access debugging', [
                'request_id' => $requestId,
                'input_email' => $request->input('email'),
                'get_email' => $request->get('email'),
                'email_direct' => $request->email,
                'post_email' => $request->post('email'),
                'all_data' => $request->all(),
                'raw_body_length' => strlen($request->getContent()),
            ]);

            // Guard: Email must be present and valid
            $rawEmail = $request->input('email') ?? $request->get('email') ?? $request->post('email');
            if (empty($rawEmail)) {
                \Log::warning('Email is missing from registration request', [
                    'request_id' => $requestId,
                    'tried_input' => $request->input('email'),
                    'tried_get' => $request->get('email'),
                    'tried_post' => $request->post('email'),
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Email is required for registration',
                    'code' => 'MISSING_EMAIL'
                ], 422);
            }

            // Normalize email (trim and lowercase) for consistent checking
            $email = strtolower(trim($rawEmail));

            \Log::info('Email normalized', [
                'request_id' => $requestId,
                'email' => $email
            ]);

            // Check if registration with this email already exists (case-insensitive)
            try {
                $existingRegistration = VendorRegistration::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

                if ($existingRegistration) {
                    // Guard: Check if status is valid
                    $status = strtolower(trim($existingRegistration->status ?? 'pending'));

                    if ($status === 'pending') {
                        \Log::info('Existing pending registration found', [
                            'request_id' => $requestId,
                            'email' => $email,
                            'registration_id' => $existingRegistration->id
                        ]);
                        return response()->json([
                            'success' => true,
                            'message' => 'Vendor registration already submitted and pending approval',
                            'registration' => [
                                'id' => $existingRegistration->id,
                                'companyName' => $existingRegistration->company_name,
                                'status' => $existingRegistration->status,
                            ]
                        ], 200);
                    }

                    // If it's approved or rejected, return appropriate message
                    $message = ($status === 'approved')
                        ? 'A vendor registration with this email has already been approved.'
                        : 'A vendor registration with this email already exists.';

                    \Log::warning('Duplicate registration attempt', [
                        'request_id' => $requestId,
                        'email' => $email,
                        'status' => $status
                    ]);

                    return response()->json([
                        'success' => false,
                        'error' => $message,
                        'code' => 'DUPLICATE_EMAIL'
                    ], 422);
                }
            } catch (\Exception $e) {
                \Log::error('Error checking for existing registration', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Error during registration check. Please try again.',
                    'code' => 'REGISTRATION_CHECK_ERROR'
                ], 500);
            }

            // Financial and country fields (optional, from FormData snake_case)
            $bankName = trim($request->input('bank_name') ?? '');
            $accountNumber = $request->input('account_number'); // Don't trim - sensitive data
            $accountName = trim($request->input('account_name') ?? '');
            $currency = trim($request->input('currency') ?? '');
            // Accept country in any of these keys: financial_country_code, country_code, countryCode
            $financialCountryCode = trim(
                $request->input('financial_country_code')
                ?? $request->input('country_code')
                ?? $request->input('countryCode')
                ?? ''
            );

            // Business profile fields (accept both camelCase and snake_case from the form)
            $rawAnnualRevenue = $request->input('annualRevenue', $request->input('annual_revenue'));
            $rawNumberOfEmployees = $request->input('numberOfEmployees', $request->input('number_of_employees'));
            $rawYearEstablished = $request->input('yearEstablished', $request->input('year_established'));
            $rawWebsite = trim((string) ($request->input('website') ?? ''));

            // Normalise annual revenue: accept numeric strings and remove commas/currency symbols
            $annualRevenue = null;
            if ($rawAnnualRevenue !== null && $rawAnnualRevenue !== '') {
                $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $rawAnnualRevenue);
                if ($cleaned !== '' && is_numeric($cleaned)) {
                    $annualRevenue = (float) $cleaned;
                }
            }

            // Number of employees may be a numeric string or a range like "11-50"; keep as string
            $numberOfEmployees = null;
            if ($rawNumberOfEmployees !== null && trim((string) $rawNumberOfEmployees) !== '') {
                $numberOfEmployees = trim((string) $rawNumberOfEmployees);
            }

            // Year established must be a 4-digit year
            $yearEstablished = null;
            if ($rawYearEstablished !== null && $rawYearEstablished !== '') {
                $yearInt = (int) preg_replace('/[^0-9]/', '', (string) $rawYearEstablished);
                if ($yearInt >= 1800 && $yearInt <= (int) date('Y')) {
                    $yearEstablished = $yearInt;
                }
            }

            $website = $rawWebsite === '' ? null : $rawWebsite;

            \Log::info('Vendor registration business profile fields parsed', [
                'request_id' => $requestId,
                'annual_revenue_raw' => $rawAnnualRevenue,
                'annual_revenue' => $annualRevenue,
                'number_of_employees_raw' => $rawNumberOfEmployees,
                'number_of_employees' => $numberOfEmployees,
                'year_established_raw' => $rawYearEstablished,
                'year_established' => $yearEstablished,
                'website' => $website,
            ]);

            // Prepare validation data with normalized email
            $validationData = [
                'companyName' => $companyName,
                'category' => $category,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'taxId' => $taxId,
                'contactPerson' => $contactPerson,
                'bank_name' => empty($bankName) ? null : $bankName,
                'account_number' => $accountNumber,
                'account_name' => empty($accountName) ? null : $accountName,
                'currency' => empty($currency) ? null : $currency,
                'financial_country_code' => empty($financialCountryCode) ? null : $financialCountryCode,
                'annual_revenue' => $annualRevenue,
                'number_of_employees' => $numberOfEmployees,
                'year_established' => $yearEstablished,
                'website' => $website,
            ];

            // Validate input data
            $validator = Validator::make($validationData, [
                'companyName' => 'required|string|max:255',
                'category' => 'required|string|max:255',
                'email' => 'required|email',
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:1000',
                'taxId' => 'nullable|string|max:255',
                'contactPerson' => 'nullable|string|max:255',
                'documents' => 'nullable|array',
                'documents.*' => 'file|max:10240', // Max 10MB per file
                'bank_name' => 'nullable|string|max:255',
                'account_number' => 'nullable|string|max:64',
                'account_name' => 'nullable|string|max:255',
                'currency' => 'nullable|string|size:3',
                'financial_country_code' => 'nullable|string|size:2',
                'annual_revenue' => 'nullable|numeric|min:0',
                'number_of_employees' => 'nullable|string|max:50',
                'year_established' => 'nullable|integer|min:1800|max:' . date('Y'),
                'website' => 'nullable|url|max:255',
            ]);

            if ($validator->fails()) {
                \Log::warning('Validation failed', [
                    'request_id' => $requestId,
                    'errors' => $validator->errors()->toArray()
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'code' => 'VALIDATION_ERROR'
                ], 422);
            }

            \Log::info('Validation passed, creating registration', [
                'request_id' => $requestId,
                'company_name' => $companyName,
                'category' => $category,
                'email' => $email,
                'has_financial' => !empty($bankName) || !empty($accountNumber) || !empty($accountName) || !empty($currency)
            ]);

            // Create the vendor registration with proper error handling
            try {
                $registration = VendorRegistration::create([
                    'company_name' => $companyName,
                    'category' => $category,
                    'email' => $email,
                    'phone' => empty($phone) ? null : $phone,
                    'address' => empty($address) ? null : $address,
                    'country_code' => empty($financialCountryCode) ? null : $financialCountryCode,
                    'bank_name' => empty($bankName) ? null : $bankName,
                    'account_number' => $accountNumber,
                    'account_name' => empty($accountName) ? null : $accountName,
                    'annual_revenue' => $annualRevenue,
                    'number_of_employees' => $numberOfEmployees,
                    'year_established' => $yearEstablished,
                    'currency' => empty($currency) ? null : $currency,
                    'tax_id' => empty($taxId) ? null : $taxId,
                    'contact_person' => empty($contactPerson) ? null : $contactPerson,
                    'website' => $website,
                    'status' => VendorRegistration::STATUS_PENDING,
                ]);

                \Log::info('Registration created successfully', [
                    'request_id' => $requestId,
                    'registration_id' => $registration->id ?? 'unknown',
                    'elapsed_time' => round(microtime(true) - $startTime, 3) . 's'
                ]);
            } catch (\Exception $e) {
                \Log::error('Error creating vendor registration', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create registration. Please try again.',
                    'code' => 'REGISTRATION_CREATION_ERROR',
                    'message' => config('app.debug') ? $e->getMessage() : null
                ], 500);
            }

            // Guard: Check if registration was created properly
            if (!$registration || !isset($registration->id)) {
                \Log::error('Registration created but ID is missing', [
                    'request_id' => $requestId,
                    'registration' => $registration
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Registration created but ID is missing',
                    'code' => 'INVALID_REGISTRATION_ID'
                ], 500);
            }

            // Handle document uploads if provided
            $documentCount = 0;
            if ($request->hasFile('documents')) {
                try {
                    $documents = $request->file('documents');

                    // Guard: Normalize to array
                    if (!is_array($documents)) {
                        $documents = [$documents];
                    }

                    // Filter out null values and invalid documents
                    $documents = array_filter($documents, function($doc) {
                        return $doc !== null && $doc !== '';
                    });

                    if (!empty($documents)) {
                        \Log::info('Processing document uploads', [
                            'request_id' => $requestId,
                            'registration_id' => $registration->id,
                            'document_count' => count($documents)
                        ]);

                        $documentService->storeDocuments($registration, $documents);
                        $documentCount = count($documents);

                        \Log::info('Documents stored successfully', [
                            'request_id' => $requestId,
                            'registration_id' => $registration->id,
                            'document_count' => $documentCount
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Error storing vendor documents', [
                        'request_id' => $requestId,
                        'registration_id' => $registration->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Continue with registration even if documents fail
                }
            }

            // Send notification to procurement managers
            try {
                if (isset($this->notificationService)) {
                    $this->notificationService->notifyVendorRegistration($registration);
                }
            } catch (\Exception $e) {
                \Log::error('Error sending vendor registration notification', [
                    'request_id' => $requestId,
                    'registration_id' => $registration->id,
                    'error' => $e->getMessage()
                ]);
                // Continue - notification failure shouldn't block registration
            }

          // Prepare response
            $response = [
                'success' => true,
                'message' => 'Vendor registration submitted successfully',
                'registration' => [
                    'id' => $registration->id,
                    'companyName' => $registration->company_name,
                    'status' => $registration->status,
                    'documentCount' => $documentCount,
                ]
            ];

            // Log BEFORE returning to ensure timing is captured without interfering with the response stream
            \Log::info('Vendor registration completed successfully', [
                'request_id' => $requestId ?? 'N/A',
                'registration_id' => $registration->id,
                'total_time' => isset($startTime) ? (round(microtime(true) - $startTime, 3) . 's') : '0s'
            ]);

            // Explicitly clear any existing output buffers to prevent "empty response" errors
            if (ob_get_length()) ob_clean();

            return response()->json($response, 201);

        } catch (\Throwable $e) {
            \Log::error('Unexpected error during vendor registration', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
                'elapsed_time' => round(microtime(true) - $startTime, 3) . 's'
            ]);

            return response()->json([
                'success' => false,
                'error' => 'An unexpected error occurred during registration. Please try again or contact support.',
                'code' => 'UNEXPECTED_ERROR',
                'message' => config('app.debug') ? $e->getMessage() : null,
                'request_id' => $requestId
            ], 500);
        }
    }
    /**
     * Get all vendor registrations (procurement_manager and supply_chain_director only)
     */
    public function registrations(Request $request)
    {
        $user = $request->user();

        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain',
            'executive',
            'chairman',
            'admin'
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $query = VendorRegistration::with(['vendor', 'approver', 'documents']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $registrations = $query->orderBy('created_at', 'desc')->get();

        $mappedRegistrations = $registrations->map(function ($registration) {
        return [
            'id' => (string) $registration->id,
            'companyName' => $registration->company_name,
            'category' => $registration->category,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'address' => $registration->address,
            'taxId' => $registration->tax_id,
            'contactPerson' => $registration->contact_person,
            'status' => $registration->status,
            'submittedDate' => optional($registration->created_at)?->toIso8601String(),
            'reviewedDate' => optional($registration->approved_at)?->toIso8601String(),
            'reviewedBy' => $registration->approver->name ?? null,
            'reviewNotes' => $registration->approval_remarks,
            ];
        });

            return response()->json([
                'success' => true,
                'data' => $mappedRegistrations
            ]);
        }
    /**
     * Get a single vendor registration by ID
     */
    public function getRegistration(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $registration = VendorRegistration::with(['vendor', 'approver', 'documents'])->find($id);

        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Format documents with download URLs using VendorDocumentService
        // Priority: Use documents relationship (table) first, then fallback to JSON column
        $formattedDocuments = [];
        $documentService = app(VendorDocumentService::class);

        // First, try to get documents from the relationship (more reliable)
        // Check if documents relationship is loaded (will be a Collection)
        $documentRecords = null;
        if ($registration->relationLoaded('documents')) {
            $documentRecords = ($registration->documents instanceof \Illuminate\Support\Collection) ? $registration->documents : collect(is_array($registration->documents) ? $registration->documents : []);
        } else {
            // Try to load the relationship
            try {
                $registration->load('documents');
                $documentRecords = ($registration->documents instanceof \Illuminate\Support\Collection) ? $registration->documents : collect(is_array($registration->documents) ? $registration->documents : []);
            } catch (\Exception $e) {
                \Log::warning('Failed to load documents relationship', [
                    'registration_id' => $registration->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // If no documents in relationship, try JSON column
        if (!$documentRecords || $documentRecords->isEmpty()) {
            // Access the JSON column directly (not the relationship)
            $jsonDocuments = $registration->getAttribute('documents');
            $documentMetadata = is_array($jsonDocuments) ? $jsonDocuments : [];

            if (!empty($documentMetadata)) {
                // Convert JSON array to collection-like structure
                $documentRecords = collect($documentMetadata)->map(function($doc) {
                    return (object) $doc; // Convert to object for consistency
                });
            } else {
                $documentRecords = collect([]);
            }
        }

        foreach ($documentRecords as $doc) {
            // Handle both Eloquent models and array/object data
            $docId = is_object($doc) && isset($doc->id) ? $doc->id : (is_array($doc) ? ($doc['id'] ?? null) : null);
            $filePath = is_object($doc) && isset($doc->file_path) ? $doc->file_path : (is_array($doc) ? ($doc['file_path'] ?? null) : null);
            $fileName = is_object($doc) && isset($doc->file_name) ? $doc->file_name : (is_array($doc) ? ($doc['file_name'] ?? 'Unknown') : 'Unknown');
            $fileType = is_object($doc) && isset($doc->file_type) ? $doc->file_type : (is_array($doc) ? ($doc['file_type'] ?? null) : null);
            $fileSize = is_object($doc) && isset($doc->file_size) ? $doc->file_size : (is_array($doc) ? ($doc['file_size'] ?? null) : null);
            $fileShareUrl = is_object($doc) && isset($doc->file_share_url) ? $doc->file_share_url : (is_array($doc) ? ($doc['file_share_url'] ?? null) : null);
            $uploadedAt = is_object($doc) && isset($doc->uploaded_at) ? $doc->uploaded_at : (is_array($doc) ? ($doc['uploaded_at'] ?? now()->toIso8601String()) : now()->toIso8601String());

            $fileUrl = null;

            if ($filePath && !$fileShareUrl) {
                try {
                    $fileUrl = $documentService->getDocumentUrl(
                        $filePath,
                        $docId,
                        $registration->id,
                        $registration->status === VendorRegistration::STATUS_APPROVED
                    );
                } catch (\Exception $e) {
                    \Log::warning("Failed to generate document URL for {$filePath}: " . $e->getMessage());
                    // Fallback to API download endpoint
                    if ($docId) {
                        $fileUrl = url("/api/vendors/registrations/{$registration->id}/documents/{$docId}/download");
                    }
                }
            } else if ($fileShareUrl) {
                // If we have a share URL (S3 or other storage), use it as the file URL
                $fileUrl = $fileShareUrl;
            } else if ($docId) {
                // Last resort: use download endpoint
                $fileUrl = url("/api/vendors/registrations/{$registration->id}/documents/{$docId}/download");
            }

            // Format uploaded_at
            $uploadedAtFormatted = $uploadedAt;
            if ($uploadedAt instanceof \Carbon\Carbon || $uploadedAt instanceof \DateTime) {
                $uploadedAtFormatted = $uploadedAt->toIso8601String();
            } elseif (is_string($uploadedAt)) {
                // Already a string, keep as is
            } else {
                $uploadedAtFormatted = now()->toIso8601String();
            }

            $formattedDocuments[] = [
                'id' => (string) ($docId ?? ''),
                'type' => $fileType,
                'fileName' => $fileName,
                'name' => $fileName,
                'filePath' => $filePath,
                'fileUrl' => $fileUrl,
                'file_url' => $fileUrl,
                'url' => $fileUrl,
                'file_share_url' => $fileShareUrl,
                'fileShareUrl' => $fileShareUrl, // Also include camelCase for frontend compatibility
                'fileSize' => $fileSize,
                'fileData' => $fileUrl,
                'uploadedAt' => $uploadedAtFormatted,
            ];
        }

        $mappedRegistration = [
            'id' => (string) $registration->id,
            'companyName' => $registration->company_name,
            'category' => $registration->category,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'address' => $registration->address,
            'taxId' => $registration->tax_id,
            'contactPerson' => $registration->contact_person,
            'status' => $registration->status,
            'rejectionReason' => $registration->rejection_reason,
            'approvalRemarks' => $registration->approval_remarks,
            'vendorId' => $registration->vendor ? $registration->vendor->vendor_id : null,
            'documents' => $formattedDocuments,
            'approvedBy' => $registration->approver ? [
                'id' => $registration->approver->id,
                'name' => $registration->approver->name,
                'email' => $registration->approver->email,
            ] : null,
            'approvedAt' => $registration->approved_at
                ? \Carbon\Carbon::parse($registration->approved_at)->toIso8601String()
                : null,
            'createdAt' => $registration->created_at
                ? \Carbon\Carbon::parse($registration->created_at)->toIso8601String()
                : null,
            'updatedAt' => $registration->updated_at
                ? \Carbon\Carbon::parse($registration->updated_at)->toIso8601String()
                : null,
            // Financial and country (masked account number for display)
            'countryCode' => $registration->country_code,
            'bankName' => $registration->bank_name,
            'accountNumber' => $this->maskAccountNumber($registration->account_number),
            'accountName' => $registration->account_name,
            'currency' => $registration->currency,
        ];

        return response()->json([
            'success' => true,
            'data' => $mappedRegistration,
        ]);
    }

    /**
     * backfill vendor Profiles
     */
    public function backfillProfiles()
    {
        $vendors = Vendor::whereNull('annual_revenue')->get();

        foreach ($vendors as $vendor) {
            $registration = \App\Models\VendorRegistration::where('email', $vendor->email)
                ->latest()
                ->first();

            if (!$registration) continue;

            $vendor->update([
                'annual_revenue'      => $registration->annual_revenue,
                'number_of_employees' => $registration->number_of_employees,
                'year_established'    => $registration->year_established,
                'website'             => $registration->website,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor profiles backfilled successfully',
        ]);
    }
    /**
     * Approve vendor registration (procurement_manager and supply_chain_director only)
     */
    public function approveRegistration(Request $request, $id, VendorApprovalService $approvalService)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];

        $hasAllowedRole =
            (isset($user->role) && in_array($user->role, $allowedRoles)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (!$hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $registration = VendorRegistration::find($id);

        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if ($registration->status !== VendorRegistration::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'error' => 'Registration is not in Pending status',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        try {
            // Use approval service to handle the complete approval process
            $result = $approvalService->approveVendor($registration, $user->id);

            // Update approval remarks if provided
            if ($request->has('remarks')) {
                $registration->update([
                    'approval_remarks' => $request->remarks,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Vendor registration approved. User account created and email sent.',
                'vendor' => [
                    'id' => $result['vendor']->vendor_id,
                    'name' => $result['vendor']->name,
                    'status' => $result['vendor']->status,
                ],
                'user' => [
                    'id' => $result['user']->id,
                    'email' => $result['user']->email,
                ],
                'registration' => [
                    'id' => $registration->id,
                    'status' => $registration->status,
                ],
                'temporaryPassword' => $result['temporary_password'] ?? null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle duplicate email error
            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return response()->json([
                    'success' => false,
                    'error' => 'A user with this email already exists. The vendor may have already been approved.',
                    'code' => 'DUPLICATE_EMAIL'
                ], 422);
            }

            // Log the full error for debugging
            \Log::error('Vendor approval database error: ' . $e->getMessage(), [
                'registration_id' => $id,
                'user_id' => $user->id,
                'sql_state' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return detailed error in non-production or if debug is enabled
            $errorMessage = config('app.debug') || config('app.env') !== 'production'
                ? $e->getMessage()
                : 'Database error during vendor approval. Please check the logs.';

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'code' => 'DATABASE_ERROR',
                'sql_state' => $e->getCode(),
            ], 500);
        } catch (\Exception $e) {
            // Log the full error for debugging
            \Log::error('Vendor approval error: ' . $e->getMessage(), [
                'registration_id' => $id,
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString()
            ]);

            // Return detailed error in non-production or if debug is enabled
            $errorMessage = config('app.debug') || config('app.env') !== 'production'
                ? $e->getMessage() . ' (File: ' . basename($e->getFile()) . ', Line: ' . $e->getLine() . ')'
                : ($e->getMessage() ?: 'An unexpected error occurred during vendor approval.');

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'code' => 'APPROVAL_ERROR'
            ], 500);
        }
    }

    /**
     * Reject vendor registration (procurement_manager and supply_chain_director only)
     */
    public function rejectRegistration(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];

        $hasAllowedRole =
            (isset($user->role) && in_array($user->role, $allowedRoles)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (!$hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $registration = VendorRegistration::find($id);

        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if ($registration->status !== VendorRegistration::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'error' => 'Registration is not in Pending status',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rejectionReason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update registration status
        $registration->update([
            'status' => VendorRegistration::STATUS_REJECTED,
            'rejection_reason' => $request->rejectionReason,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor registration rejected',
            'registration' => [
                'id' => $registration->id,
                'status' => $registration->status,
                'rejectionReason' => $registration->rejection_reason,
            ]
        ]);
    }

    /**
     * Update vendor credentials (procurement_manager and supply_chain_director only)
     * Supports both manual password setting and automatic password reset
     */
    public function updateVendorCredentials(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Find vendor by either primary key or vendor_id
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND',
                'debug' => [
                    'searchedId' => $id,
                    'searchedType' => is_numeric($id) ? 'numeric (tried both primary key and vendor_id)' : 'string (tried vendor_id only)'
                ]
            ], 404);
        }

        // Find the user account associated with this vendor
        $vendorUser = User::where('vendor_id', $vendor->id)->first();

        if (!$vendorUser) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor user account not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if this is an automatic password reset request
        if ($request->boolean('resetPassword', false)) {
            // Generate random secure password (12 characters)
            $newPassword = Str::random(12);

            // Update password and force change on next login
            $vendorUser->update([
                'password' => Hash::make($newPassword),
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vendor password has been reset. The vendor will be required to change this temporary password on next login.',
                'data' => [
                    'temporaryPassword' => $newPassword,
                ],
                'user' => [
                    'id' => $vendorUser->id,
                    'email' => $vendorUser->email,
                ]
            ]);
        }

        // Manual password setting (existing behavior)
        $validator = Validator::make($request->all(), [
            'newPassword' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update password and force change on next login
        $vendorUser->update([
            'password' => Hash::make($request->newPassword),
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor credentials updated. Vendor will be required to change password on next login.',
            'user' => [
                'id' => $vendorUser->id,
                'email' => $vendorUser->email,
            ]
        ]);
    }

    /**
     * Update vendor profile fields (admin-only endpoint)
     * Allows procurement managers and supply chain directors to update specific vendor profile fields
     */
    public function adminUpdate(Request $request, string $uuid)
    {
        $user = $request->user();

        // Check permission - only procurement_manager and supply_chain_director
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Find vendor by vendor_id or primary key
        $vendor = $this->findVendor($uuid);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Validate input - accept only specified profile fields
        $validator = Validator::make($request->all(), [
            'annual_revenue' => 'nullable|string',
            'number_of_employees' => 'nullable|string',
            'year_established' => 'nullable|integer|min:1900|max:' . date('Y'),
            'website' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update only the allowed fields
        $vendor->update($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $vendor->fresh(),
        ]);
    }

    /**
     * Delete a vendor
     * Soft deletes the vendor and deactivates their user account
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Find vendor by either primary key or vendor_id
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND',
                'debug' => [
                    'searchedId' => $id,
                    'searchedType' => is_numeric($id) ? 'numeric (tried both primary key and vendor_id)' : 'string (tried vendor_id only)'
                ]
            ], 404);
        }

        // Check if vendor has active orders or quotations
        $activeQuotations = $vendor->quotations()
            ->whereIn('status', ['Pending', 'Approved'])
            ->count();

        if ($activeQuotations > 0) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot delete vendor with active quotations. Please complete or reject all pending quotations first.',
                'code' => 'VENDOR_HAS_ACTIVE_QUOTATIONS',
                'activeQuotations' => $activeQuotations
            ], 422);
        }

        // Deactivate associated user account if exists
        $vendorUser = User::where('vendor_id', $vendor->id)->first();
        if ($vendorUser) {
            // Revoke all tokens
            $vendorUser->tokens()->delete();

            // Delete user account
            $vendorUser->delete();
        }

        // Delete the vendor
        $vendorName = $vendor->name;
        $vendorId = $vendor->vendor_id;
        $vendor->delete();

        return response()->json([
            'success' => true,
            'message' => "Vendor '{$vendorName}' has been successfully deleted.",
            'data' => [
                'vendorId' => $vendorId,
                'vendorName' => $vendorName,
            ]
        ]);
    }

    /**
     * Download a vendor registration document
     */
    public function downloadDocument(Request $request, $registrationId, $documentId, VendorDocumentService $documentService)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Verify registration exists
        $registration = VendorRegistration::find($registrationId);
        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Find the document
        $document = VendorRegistrationDocument::where('id', $documentId)
            ->where('vendor_registration_id', $registrationId)
            ->first();

        if (!$document) {
            return response()->json([
                'success' => false,
                'error' => 'Document not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if document has expired
        if ($document->expiryDate && \Carbon\Carbon::parse($document->expiryDate)->isPast()) {
            return response()->json([
                'success' => false,
                'error' => 'Document has expired and cannot be downloaded',
                'code' => 'DOCUMENT_EXPIRED',
                'expiry_date' => $document->expiryDate
            ], 410); // 410 Gone status
        }

        // Check if document status is marked as Expired
        if ($document->status === 'Expired') {
            return response()->json([
                'success' => false,
                'error' => 'Document is no longer available',
                'code' => 'DOCUMENT_EXPIRED',
                'expiry_date' => $document->expiryDate
            ], 410); // 410 Gone status
        }

        // Check if document has a share URL (S3 temporary URL or other)
        if ($document->file_share_url) {
            // Redirect to share URL for download
            return redirect($document->file_share_url);
        }

        // Get document content from S3/local storage
        $content = $documentService->getDocumentContent($document);

        if ($content === false) {
            return response()->json([
                'success' => false,
                'error' => 'Document file not found in storage.',
                'code' => 'FILE_NOT_FOUND',
                'has_share_url' => !empty($document->file_share_url),
                'share_url' => $document->file_share_url
            ], 404);
        }

        // Determine content type from file extension if not set
        $contentType = $document->file_type;
        if (!$contentType) {
            $extension = strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'txt' => 'text/plain',
                'zip' => 'application/zip',
                'rar' => 'application/x-rar-compressed',
            ];
            $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
        }

        // Return file as download
        return response($content)
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'attachment; filename="' . $document->file_name . '"')
            ->header('Content-Length', strlen($content));
    }

    /**
     * Add rating/comment to vendor
     */
    public function addRating(Request $request, $id)
    {
        $user = $request->user();

        // Validate input
        $validator = Validator::make($request->all(), [
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Find vendor
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Create rating
        $rating = VendorRating::create([
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
        ]);

        // Recalculate vendor average rating
        $this->updateVendorAverageRating($vendor);

        // Get all ratings with user info
        $ratings = VendorRating::where('vendor_id', $vendor->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($r) {
                return [
                    'id' => $r->id,
                    'comment' => $r->comment,
                    'rating' => (float) $r->rating,
                    'createdAt' => $r->created_at
                        ? \Carbon\Carbon::parse($r->created_at)->toIso8601String()
                        : null,
                    'createdBy' => [
                        'id' => $r->user->id,
                        'name' => $r->user->name,
                        'email' => $r->user->email,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Rating added successfully',
            'rating' => (float) $vendor->fresh()->rating,
            'comments' => $ratings,
        ]);
    }

    /**
     * Get vendor comments/ratings
     */
    public function getComments($id)
    {
        // Find vendor
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get all ratings with user info
        $comments = VendorRating::where('vendor_id', $vendor->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($r) {
                return [
                    'id' => $r->id,
                    'comment' => $r->comment,
                    'rating' => (float) $r->rating,
                    'createdAt' => $r->created_at
                        ? \Carbon\Carbon::parse($r->created_at)->toIso8601String()
                        : null,
                    'createdBy' => [
                        'id' => $r->user->id,
                        'name' => $r->user->name,
                        'email' => $r->user->email,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $comments,
            'vendorRating' => (float) ($vendor->rating ?? 0),
            'totalComments' => $comments->count(),
        ]);
    }

    /**
     * Update vendor average rating
     */
    private function updateVendorAverageRating(Vendor $vendor): void
    {
        $avgRating = VendorRating::where('vendor_id', $vendor->id)
            ->avg('rating');

        $vendor->update([
            'rating' => $avgRating ? round($avgRating, 2) : 0,
        ]);
    }

    /**
     * Get quotations for the logged-in vendor (vendor-specific endpoint)
     */
    public function getVendorQuotations(Request $request)
    {
        $user = $request->user();

        // Verify user is a vendor - check both direct role field and Spatie roles
        $isVendor = false;
        if ($user->role === 'vendor') {
            $isVendor = true;
        } elseif (method_exists($user, 'hasRole') && $user->hasRole('vendor')) {
            $isVendor = true;
        }

        if (!$isVendor) {
            return response()->json([
                'success' => false,
                'error' => 'Only vendors can access this endpoint',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get vendor from authenticated user - try multiple methods
        $vendor = null;

        // Method 1: Try vendor relationship
        if ($user->vendor_id && method_exists($user, 'vendor')) {
            $vendor = $user->vendor;
        }

        // Method 2: Find vendor by vendor_id if relationship didn't work
        if (!$vendor && $user->vendor_id) {
            $vendor = Vendor::find($user->vendor_id);
        }

        // Method 3: Try finding vendor by email as last resort
        if (!$vendor) {
            $vendor = Vendor::where('email', $user->email)->first();
        }

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found. Please ensure your account is linked to a vendor.',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Build query for quotations - ensure MRF relationship is loaded for title generation
        $query = Quotation::where('vendor_id', $vendor->id)
            ->with(['rfq.mrf', 'rfq.items', 'approver']);

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by RFQ ID if provided
        if ($request->has('rfqId')) {
            $rfq = \App\Models\RFQ::where('rfq_id', $request->rfqId)->first();
            if ($rfq) {
                $query->where('rfq_id', $rfq->id);
            }
        }

        // Order by most recent first
        $quotations = $query->orderBy('created_at', 'desc')->get();

        // Format quotations for response
        $formattedQuotations = $quotations->map(function($quotation) use ($vendor) {
            // Safely format dates to prevent time errors
            $formatDate = function($date) {
                if (!$date) {
                    return null;
                }
                try {
                    if ($date instanceof \Carbon\Carbon || $date instanceof \DateTime) {
                        return $date->toIso8601String();
                    }
                    return null;
                } catch (\Exception $e) {
                    \Log::warning("Date formatting error: " . $e->getMessage());
                    return null;
                }
            };

            $formatDateOnly = function($date) {
                if (!$date) {
                    return null;
                }
                try {
                    if ($date instanceof \Carbon\Carbon || $date instanceof \DateTime) {
                        return $date->format('Y-m-d');
                    }
                    return null;
                } catch (\Exception $e) {
                    \Log::warning("Date formatting error: " . $e->getMessage());
                    return null;
                }
            };

            return [
                'id' => $quotation->quotation_id,
                'quoteNumber' => $quotation->quote_number,
                'rfqId' => $quotation->rfq ? $quotation->rfq->rfq_id : null,
                'rfqTitle' => $quotation->rfq ? $quotation->rfq->getDisplayTitle() : 'Unknown RFQ',
                'mrfId' => $quotation->rfq && $quotation->rfq->mrf ? $quotation->rfq->mrf->mrf_id : null,
                'mrfTitle' => $quotation->rfq && $quotation->rfq->mrf ? $quotation->rfq->mrf->title : null,
                'vendorId' => $vendor->vendor_id,
                'vendorName' => $quotation->vendor_name,
                'price' => (float) $quotation->price,
                'totalAmount' => (float) $quotation->total_amount,
                'currency' => $quotation->currency ?? 'NGN',
                'deliveryDays' => $quotation->delivery_days ?? null,
                'deliveryDate' => $formatDateOnly($quotation->delivery_date),
                'paymentTerms' => $quotation->payment_terms ?? null,
                'validityDays' => $quotation->validity_days,
                'warrantyPeriod' => $quotation->warranty_period,
                'notes' => $quotation->notes,
                'status' => $quotation->status,
                'reviewStatus' => $quotation->review_status ?? 'pending',
                'rejectionReason' => $quotation->rejection_reason,
                'revisionNotes' => $quotation->revision_notes,
                'approvalRemarks' => $quotation->approval_remarks,
                'attachments' => app(QuotationAttachmentService::class)->hydrateAttachments((function($attachments) {
                    if ($attachments === null || $attachments === '' || $attachments === []) {
                        return [];
                    }

                    if (is_string($attachments)) {
                        return [$attachments];
                    }

                    if (!is_array($attachments)) {
                        return [];
                    }

                    $isAssoc = array_keys($attachments) !== range(0, count($attachments) - 1);
                    if ($isAssoc) {
                        return [$attachments];
                    }

                    $out = [];
                    foreach ($attachments as $a) {
                        if ($a === null || $a === '') {
                            continue;
                        }

                        if (is_string($a)) {
                            $out[] = $a;
                            continue;
                        }

                        if (!is_array($a)) {
                            continue;
                        }

                        $aIsAssoc = array_keys($a) !== range(0, count($a) - 1);
                        if ($aIsAssoc) {
                            $out[] = $a;
                            continue;
                        }

                        foreach ($a as $inner) {
                            if ($inner !== null && $inner !== '') {
                                $out[] = $inner;
                            }
                        }
                    }

                    return array_values($out);
                })($quotation->attachments)),
                'submittedAt' => $formatDate($quotation->submitted_at),
                'reviewedAt' => $formatDate($quotation->reviewed_at),
                'approvedAt' => $formatDate($quotation->approved_at),
                'approvedBy' => $quotation->approver ? [
                    'id' => $quotation->approver->id,
                    'name' => $quotation->approver->name,
                    'email' => $quotation->approver->email,
                ] : null,
                'createdAt' => $formatDate($quotation->created_at) ?? ($quotation->created_at ? $quotation->created_at : null),
                'updatedAt' => $formatDate($quotation->updated_at) ?? ($quotation->updated_at ? $quotation->updated_at : null),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedQuotations,
            'count' => $formattedQuotations->count(),
            'vendor' => [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
            ],
        ]);
    }

    /**
     * Send vendor invitation email
     */
    public function inviteVendor(Request $request)
    {
        $user = $request->user();

        // Check permissions - only procurement managers and higher
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain',
            'executive',
            'chairman',
            'admin',
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions to invite vendors',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'company_name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Validate category is in allowed list
        if (!VendorCategory::isValid($request->category)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid vendor category',
                'code' => 'INVALID_CATEGORY',
                'valid_categories' => VendorCategory::values()
            ], 422);
        }

        // Check if vendor already exists with this email
        $existingVendor = Vendor::where('email', $request->email)->first();
        if ($existingVendor) {
            return response()->json([
                'success' => false,
                'error' => 'A vendor with this email already exists',
                'code' => 'VENDOR_EXISTS'
            ], 409);
        }

        // Check if there's already a pending registration with this email
        $pendingRegistration = VendorRegistration::where('email', $request->email)
            ->whereIn('status', ['pending', 'Pending'])
            ->first();

        if ($pendingRegistration) {
            return response()->json([
                'success' => false,
                'error' => 'A registration with this email is already pending',
                'code' => 'REGISTRATION_PENDING'
            ], 409);
        }

        // Send invitation email
        $emailService = app(\App\Services\EmailService::class);
        $emailSent = $emailService->sendVendorInvitation(
            $request->email,
            $request->company_name
        );

        if (!$emailSent) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send invitation email',
                'code' => 'EMAIL_FAILED'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor invitation sent successfully',
            'data' => [
                'email' => $request->email,
                'companyName' => $request->company_name,
                'category' => $request->category,
                'sentAt' => now()->toIso8601String(),
                'sentBy' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]
        ], 200);
    }

    /**
     * Get vendor registration documents expiring within N days
     * Query parameters: days (default 30)
     */
    public function getExpiringDocuments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $user = $request->user();
        $daysToCheck = $request->input('days', 30);
        $now = \Carbon\Carbon::now();
        $expiryThreshold = $now->copy()->addDays($daysToCheck);

        // Get expiring documents (expiring within N days but not yet expired)
        $expiringDocuments = VendorRegistrationDocument::whereNotNull('expiryDate')
            ->where('expiryDate', '>', $now)
            ->where('expiryDate', '<=', $expiryThreshold)
            ->where('status', '!=', 'Expired')
            ->with(['vendorRegistration' => function ($query) {
                $query->with(['vendor', 'applicant']);
            }])
            ->orderBy('expiryDate', 'asc')
            ->get()
            ->map(function ($document) use ($now) {
                $expiryDate = \Carbon\Carbon::parse($document->expiryDate);
                $daysUntilExpiry = $now->diffInDays($expiryDate);

                return [
                    'id' => $document->id,
                    'file_name' => $document->file_name,
                    'file_type' => $document->file_type,
                    'expiry_date' => $document->expiryDate,
                    'days_until_expiry' => $daysUntilExpiry,
                    'is_required' => $document->is_required ?? false,
                    'status' => $document->status,
                    'uploaded_at' => $document->uploaded_at,
                    'vendor_registration' => [
                        'id' => $document->vendorRegistration->id,
                        'vendor_name' => $document->vendorRegistration->vendor->name ?? $document->vendorRegistration->applicant->name ?? 'Unknown',
                        'category' => $document->vendorRegistration->vendor?->category,
                        'status' => $document->vendorRegistration->status,
                    ]
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Documents expiring within ' . $daysToCheck . ' days',
            'data' => [
                'count' => $expiringDocuments->count(),
                'days_to_check' => $daysToCheck,
                'current_date' => $now->toIso8601String(),
                'expiry_threshold' => $expiryThreshold->toIso8601String(),
                'documents' => $expiringDocuments,
            ]
        ], 200);
    }
}
