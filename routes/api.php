<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VendorAuthController;
use App\Http\Controllers\Api\ContractTypeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DashboardKpiController;
use App\Http\Controllers\Api\EligiblePassengersController;
use App\Http\Controllers\Api\AppConfigController;
use App\Http\Controllers\Api\FinanceApReportController;
use App\Http\Controllers\Api\ProcurementReportController;
use App\Http\Controllers\Api\ReportingEngineController;
use App\Http\Controllers\Api\ReportsDashboardController;
use App\Http\Controllers\Api\MRFController;
use App\Http\Controllers\Api\SRFController;
use App\Http\Controllers\Api\RFQController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\POTermsTemplateController;
use App\Http\Controllers\Api\PriceComparisonController;
use App\Http\Controllers\Api\UserSignatureFileController;
use App\Http\Controllers\Api\Admin\CodeMappingsController;
use App\Http\Controllers\Api\V1\Logistics\AuthController as LogisticsAuthController;
use App\Http\Controllers\Api\V1\Logistics\VendorController as LogisticsVendorController;
use App\Http\Controllers\Api\V1\Logistics\TripController as LogisticsTripController;
use App\Http\Controllers\Api\V1\Logistics\TripRequestWorkflowController;
use App\Http\Controllers\Api\V1\Logistics\VendorPortalTripController as LogisticsVendorPortalTripController;
use App\Http\Controllers\Api\V1\Logistics\TripVendorSubmissionController as LogisticsTripVendorSubmissionController;
use App\Http\Controllers\Api\V1\Logistics\AccommodationBookingController as LogisticsAccommodationBookingController;
use App\Http\Controllers\Api\V1\Logistics\JobCompletionCertificateController as LogisticsJobCompletionCertificateController;
use App\Http\Controllers\Api\V1\Logistics\JourneyController as LogisticsJourneyController;
use App\Http\Controllers\Api\V1\Logistics\MaterialController as LogisticsMaterialController;
use App\Http\Controllers\Api\V1\Logistics\MaterialMovementController as LogisticsMaterialMovementController;
use App\Http\Controllers\Api\V1\Logistics\MaterialJCCController as LogisticsMaterialJCCController;
use App\Http\Controllers\Api\V1\Logistics\FleetController as LogisticsFleetController;
use App\Http\Controllers\Api\V1\Logistics\FleetDriverController as LogisticsFleetDriverController;
use App\Http\Controllers\Api\V1\Logistics\FleetDriverDocumentController as LogisticsFleetDriverDocumentController;
use App\Http\Controllers\Api\V1\Logistics\FleetVehicleDocumentController as LogisticsFleetVehicleDocumentController;
use App\Http\Controllers\Api\V1\Logistics\LogisticsDashboardController;
use App\Http\Controllers\Api\V1\Logistics\DocumentController as LogisticsDocumentController;
use App\Http\Controllers\Api\V1\Logistics\NotificationController as LogisticsNotificationController;
use App\Http\Controllers\Api\V1\Logistics\ReportController as LogisticsReportController;
use App\Http\Controllers\Api\V1\Logistics\UploadController as LogisticsUploadController;
use App\Http\Controllers\Api\V1\Logistics\DocsController as LogisticsDocsController;

// API health check/test route
Route::get('/', function () {
    return response()->json([
        'message' => 'Supply Chain API is running',
        'version' => '1.0.1',
        'status' => 'ok',
        'endpoints' => [
            'auth' => [
                'login' => 'POST /api/auth/login',
                'logout' => 'POST /api/auth/logout',
                'me' => 'GET /api/auth/me',
            ],
            'mrfs' => 'GET /api/mrfs',
            'srfs' => 'GET /api/srfs',
            'rfqs' => 'GET /api/rfqs',
            'quotations' => 'GET /api/quotations',
            'vendors' => 'GET /api/vendors',
        ]
    ]);
});

// Finance AP integration (machine auth, no session)
Route::prefix('v1/integrations/scm')->middleware('finance_ap.integration')->group(function () {
    Route::get('/documents/{scm_transaction_id}/{document_id}', [\App\Http\Controllers\Api\FinanceApIntegrationDocumentController::class, 'show']);
    Route::get('/vendors/{scm_vendor_id}/open-purchase-orders', [\App\Http\Controllers\Api\FinanceApOpenPurchaseOrderController::class, 'index']);
});

Route::post('/webhooks/finance-ap', [\App\Http\Controllers\Api\FinanceApWebhookController::class, 'handle']);

// Public routes
Route::get('/cors-test', function () {
    return response()->json([
        'success' => true,
        'message' => 'CORS is working correctly',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Health check endpoint - verifies database connectivity and system status
Route::get('/health', function () {
    try {
        // Test database connection
        \DB::connection()->getPdo();
        $dbStatus = 'connected';
        $dbError = null;
    } catch (\Exception $e) {
        $dbStatus = 'disconnected';
        $dbError = $e->getMessage();
    }

    return response()->json([
        'success' => $dbStatus === 'connected',
        'status' => $dbStatus === 'connected' ? 'healthy' : 'unhealthy',
        'timestamp' => now()->toIso8601String(),
        'database' => [
            'status' => $dbStatus,
            'error' => $dbError,
            'connection' => config('database.default'),
        ],
        'server' => [
            'uptime' => time() - $_SERVER['REQUEST_TIME'] ?? 0,
            'memory_usage' => memory_get_usage() / 1024 / 1024 . 'MB',
            'memory_peak' => memory_get_peak_usage() / 1024 / 1024 . 'MB',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ],
    ], $dbStatus === 'connected' ? 200 : 503);
});

// CORS preflight test endpoint
Route::options('/vendors/register', function () {
    return response('', 204)
        ->header('Access-Control-Allow-Origin', request('Origin') ?: '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
        ->header('Access-Control-Allow-Credentials', 'true')
        ->header('Access-Control-Max-Age', '86400');
});

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/vendor/change-password', [AuthController::class, 'forcePasswordChange']);

// Vendor authentication (public)
Route::post('/vendors/auth/login', [VendorAuthController::class, 'login']);
Route::post('/vendors/auth/password-reset', [VendorAuthController::class, 'requestPasswordReset']);
Route::post('/vendors/auth/forgot-password', [VendorAuthController::class, 'requestPasswordReset']);
Route::post('/vendors/auth/request-password-reset', [VendorAuthController::class, 'requestPasswordReset']);

// ======================================
// Backward-compatibility routes at /api/
// Logistics endpoints at simple /api/ paths
// ======================================
Route::middleware('auth:sanctum')->group(function () {
    $logisticsInternalRoles = 'role:procurement_manager,logistics_manager,logistics_officer,supply_chain_director,admin,executive,chairman,finance';

    // Trip routes - forward to logistics controllers
    Route::post('/trips', [LogisticsTripController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/trips', [LogisticsTripController::class, 'index']);
    Route::get('/trips/{id}', [LogisticsTripController::class, 'show']);
    Route::post('/trips/{id}/request-changes', [TripRequestWorkflowController::class, 'requestChanges'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/director-approve', [TripRequestWorkflowController::class, 'directorApprove']);
    Route::post('/trips/{id}/director-reject', [TripRequestWorkflowController::class, 'directorReject']);
    Route::post('/trips/{id}/director-return', [TripRequestWorkflowController::class, 'directorReturn']);
    Route::put('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::patch('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
    Route::put('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/cancel', [LogisticsTripController::class, 'cancel'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/assign-resources', [LogisticsTripController::class, 'assignResources'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{id}/comments', [LogisticsTripController::class, 'getComments']);
    Route::post('/trips/{id}/comments', [LogisticsTripController::class, 'addComment']);
    Route::post('/trips/bulk-upload', [LogisticsTripController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);

    Route::post('/trips/{tripId}/invite-vendors', [LogisticsTripVendorSubmissionController::class, 'inviteVendors'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{tripId}/vendor-responses', [LogisticsTripVendorSubmissionController::class, 'getVendorResponses'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{tripId}/select-vendor', [LogisticsTripVendorSubmissionController::class, 'selectVendor'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{tripId}/submission', [LogisticsTripVendorSubmissionController::class, 'listTripSubmissions'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{tripId}/vendor-submission/{submissionId}', [LogisticsTripVendorSubmissionController::class, 'getSubmission'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{tripId}/route-to-procurement', [LogisticsTripVendorSubmissionController::class, 'routeToProcurement'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{tripId}/notify-invoice', [LogisticsTripVendorSubmissionController::class, 'notifyInvoiceSubmission'])->middleware($logisticsInternalRoles);

    Route::get('/vendor-portal/trips', [LogisticsVendorPortalTripController::class, 'indexVendorTrips']);
    Route::post('/vendor-portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'submitVendorDetails']);
    Route::post('/vendor-portal/trips/{tripId}/documents', [LogisticsVendorPortalTripController::class, 'uploadDocuments']);
    Route::get('/vendor-portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'getVendorSubmission']);

    Route::post('/logistics/accommodations', [LogisticsAccommodationBookingController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/logistics/accommodations', [LogisticsAccommodationBookingController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/logistics/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/logistics/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::patch('/logistics/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::delete('/logistics/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{tripId}/accommodations', [LogisticsAccommodationBookingController::class, 'getTripAccommodations'])->middleware($logisticsInternalRoles);

    Route::post('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::patch('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{tripId}/jcc/line-items', [LogisticsJobCompletionCertificateController::class, 'addLineItem'])->middleware($logisticsInternalRoles);
    Route::put('/trips/{tripId}/jcc/line-items/{lineItemId}', [LogisticsJobCompletionCertificateController::class, 'updateLineItem'])->middleware($logisticsInternalRoles);
    Route::delete('/trips/{tripId}/jcc/line-items/{lineItemId}', [LogisticsJobCompletionCertificateController::class, 'deleteLineItem'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{tripId}/jcc/prefill', [LogisticsJobCompletionCertificateController::class, 'prefillSuggestions'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{tripId}/jcc/prefill', [LogisticsJobCompletionCertificateController::class, 'prefill'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{tripId}/jcc/submit', [LogisticsJobCompletionCertificateController::class, 'submit'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{tripId}/jcc/approve', [LogisticsJobCompletionCertificateController::class, 'approve'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{tripId}/jcc/pdf', [LogisticsJobCompletionCertificateController::class, 'generatePdf'])->middleware($logisticsInternalRoles);

    // Journey routes - forward to logistics controllers
    Route::post('/journeys', [LogisticsJourneyController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/journeys', [LogisticsJourneyController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/journeys/{trip_id}', [LogisticsJourneyController::class, 'listByTrip'])->middleware($logisticsInternalRoles);
    Route::put('/journeys/{id}', [LogisticsJourneyController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::post('/journeys/{id}/update-status', [LogisticsJourneyController::class, 'updateStatus'])->middleware('role:vendor,logistics_officer,logistics_manager,procurement_manager,supply_chain_director,admin');

    // Fleet routes - forward to logistics controllers
    Route::post('/fleet/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::delete('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::post('/fleet/vehicles/{vehicleId}/documents', [LogisticsFleetVehicleDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/vehicles/{vehicleId}/documents', [LogisticsFleetVehicleDocumentController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::delete('/fleet/vehicles/{vehicleId}/documents/{documentId}', [LogisticsFleetVehicleDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/vehicles/{vehicleId}/maintenance', [LogisticsFleetController::class, 'listMaintenance'])->middleware($logisticsInternalRoles);
    Route::patch('/fleet/vehicles/{vehicleId}/maintenance/{scheduleId}', [LogisticsFleetController::class, 'updateMaintenance'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/maintenance/upcoming', [LogisticsFleetController::class, 'upcomingMaintenance'])->middleware($logisticsInternalRoles);
    Route::patch('/fleet/vehicles/{id}/status', [LogisticsFleetController::class, 'updateVehicleStatus'])->middleware($logisticsInternalRoles);
    Route::post('/fleet/drivers', [LogisticsFleetDriverController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/drivers', [LogisticsFleetDriverController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::patch('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::delete('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::post('/fleet/drivers/{id}/assign', [LogisticsFleetDriverController::class, 'assign'])->middleware($logisticsInternalRoles);
    Route::post('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::delete('/fleet/drivers/{driverId}/documents/{documentId}', [LogisticsFleetDriverDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::delete('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::get('/trip-requests/booking-rules', [TripRequestWorkflowController::class, 'bookingRules']);
    Route::get('/trip-requests/all', [TripRequestWorkflowController::class, 'allTrips']);
    Route::get('/trip-requests', [TripRequestWorkflowController::class, 'index']);
    Route::post('/trip-requests', [TripRequestWorkflowController::class, 'store']);
    Route::put('/trip-requests/{id}', [TripRequestWorkflowController::class, 'update']);
    Route::post('/trip-requests/{id}/confirm', [TripRequestWorkflowController::class, 'confirm']);
    Route::post('/trip-requests/{id}/forward', [TripRequestWorkflowController::class, 'forward']);
    Route::post('/trip-requests/{id}/request-changes', [TripRequestWorkflowController::class, 'requestChanges']);
    Route::post('/trip-requests/{id}/director-approve', [TripRequestWorkflowController::class, 'directorApprove']);
    Route::post('/trip-requests/{id}/director-reject', [TripRequestWorkflowController::class, 'directorReject']);
    Route::post('/trip-requests/{id}/director-return', [TripRequestWorkflowController::class, 'directorReturn']);
    Route::post('/trip-requests/{id}/convert', [TripRequestWorkflowController::class, 'convert']);
    Route::post('/trip-requests/{id}/reject', [TripRequestWorkflowController::class, 'reject']);
    Route::get('/trip-requests/{id}/comments', [TripRequestWorkflowController::class, 'getComments']);
    Route::post('/trip-requests/{id}/comments', [TripRequestWorkflowController::class, 'addComment']);
    Route::get('/trip-requests/{id}', [TripRequestWorkflowController::class, 'show']);
    Route::get('/trip-requests/{id}/progress-tracker', [TripRequestWorkflowController::class, 'progressTracker']);
    Route::delete('/trip-requests/{id}', [TripRequestWorkflowController::class, 'destroy']);
    Route::post('/trips/{id}/convert-to-logistics-request', [TripRequestWorkflowController::class, 'convertToLogisticsRequest'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/procurement-approve-quote', [TripRequestWorkflowController::class, 'procurementApproveQuote'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/scd-approve', [TripRequestWorkflowController::class, 'scdApprove'])->middleware('role:supply_chain_director,supply_chain,admin');
    Route::post('/trips/{id}/generate-trip-po', [TripRequestWorkflowController::class, 'generatePo'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/upload-signed-trip-po', [TripRequestWorkflowController::class, 'uploadSignedPo'])->middleware('role:supply_chain_director,supply_chain,admin');
    Route::post('/fleet/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
    Route::post('/fleet/vehicles/{id}/initiate-srf', [LogisticsFleetController::class, 'initiateSrf'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/alerts', [LogisticsFleetController::class, 'getAlerts'])->middleware('auth:sanctum');

    // Compatibility aliases for older clients (vehicles without /fleet prefix)
    Route::post('/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::delete('/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::post('/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
    Route::post('/vehicles/{id}/initiate-srf', [LogisticsFleetController::class, 'initiateSrf'])->middleware($logisticsInternalRoles);

    // Materials routes - forward to logistics controllers
    // NOTE: specific paths (summary, bulk-upload) must precede the parameterised
    // /materials/{id} route so they are matched before being treated as IDs.
    Route::get('/materials/summary', [LogisticsMaterialController::class, 'summary'])->middleware($logisticsInternalRoles);
    Route::get('/materials-summary', [LogisticsMaterialController::class, 'summary'])->middleware($logisticsInternalRoles);
    Route::post('/materials/bulk-upload', [LogisticsMaterialController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);
    Route::post('/materials', [LogisticsMaterialController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/materials', [LogisticsMaterialController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/materials/{id}', [LogisticsMaterialController::class, 'show'])->middleware($logisticsInternalRoles)->where('id', '[0-9]+');
    Route::delete('/materials/{id}', [LogisticsMaterialController::class, 'destroy'])->middleware($logisticsInternalRoles)->where('id', '[0-9]+');

    // Material Movements (Logistics Tracking) routes
    Route::post('/material-movements', [LogisticsMaterialMovementController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/material-movements', [LogisticsMaterialMovementController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/material-movements/{id}', [LogisticsMaterialMovementController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::patch('/material-movements/{id}', [LogisticsMaterialMovementController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::put('/material-movements/{id}', [LogisticsMaterialMovementController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::delete('/material-movements/{id}', [LogisticsMaterialMovementController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::post('/material-movements/{id}/mark-in-transit', [LogisticsMaterialMovementController::class, 'markInTransit'])->middleware($logisticsInternalRoles);
    Route::post('/material-movements/{id}/mark-delivered', [LogisticsMaterialMovementController::class, 'markDelivered'])->middleware($logisticsInternalRoles);

    // Material JCC (Job Completion Certificate) routes
    Route::post('/material-movements/{materialId}/jcc', [LogisticsMaterialJCCController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/material-movements/{materialId}/jcc', [LogisticsMaterialJCCController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/material-movements/{materialId}/jcc', [LogisticsMaterialJCCController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::patch('/material-movements/{materialId}/jcc', [LogisticsMaterialJCCController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::post('/material-movements/{materialId}/jcc/submit', [LogisticsMaterialJCCController::class, 'submit'])->middleware($logisticsInternalRoles);
    Route::post('/material-movements/{materialId}/jcc/approve', [LogisticsMaterialJCCController::class, 'approve'])->middleware($logisticsInternalRoles);
    Route::get('/material-movements/{materialId}/jcc/pdf', [LogisticsMaterialJCCController::class, 'pdf'])->middleware($logisticsInternalRoles);
    Route::get('/material-movements/{materialId}/jcc/prefill', [LogisticsMaterialJCCController::class, 'prefill'])->middleware($logisticsInternalRoles);

    // Documents routes - forward to logistics controllers
    Route::post('/documents', [LogisticsDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/documents/{entity_type}/{entity_id}', [LogisticsDocumentController::class, 'list'])->middleware($logisticsInternalRoles);
    Route::delete('/documents/{id}', [LogisticsDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);

    // Report routes - forward to logistics controllers
    Route::post('/reports', [LogisticsReportController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/reports', [LogisticsReportController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/reports/pending', [LogisticsReportController::class, 'pending'])->middleware($logisticsInternalRoles);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Authentication
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/refresh', [AuthController::class, 'refreshToken']); // Alias for frontend compatibility
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    // Self-service signature upload — used by Supply Chain Director (and
    // anyone else who signs documents) so the Settings page works without an
    // admin grant.
    Route::post('/auth/signature', [AuthController::class, 'uploadOwnSignature']);
    Route::get('/auth/signature', [AuthController::class, 'getOwnSignature']);
    Route::delete('/auth/signature', [AuthController::class, 'deleteOwnSignature']);
    Route::get('/users/{user}/signature-file', [UserSignatureFileController::class, 'show']);

    // Session management - keep-alive and status check
    Route::post('/auth/keep-alive', function () {
        return response()->json([
            'success' => true,
            'message' => 'Session active',
            'user' => [
                'id' => auth()->id(),
                'name' => auth()->user()->name,
                'email' => auth()->user()->email,
            ],
            'expires_in_minutes' => 5
        ]);
    });

    Route::get('/auth/session-status', function () {
        $user = auth()->user();
        return response()->json([
            'success' => true,
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'supply_chain_role' => $user->scmRole(),
                'hris_role' => $user->hris_role,
                'role' => $user->scmRole(),
            ],
            'session_timeout_minutes' => 5,
            'message' => 'User is authenticated and session is active'
        ]);
    });

    // Vendor authentication (protected)
    Route::post('/vendors/auth/logout', [VendorAuthController::class, 'logout']);
    Route::get('/vendors/auth/me', [VendorAuthController::class, 'me']);
    Route::post('/vendors/auth/refresh-token', [VendorAuthController::class, 'refreshToken']);
    Route::post('/vendors/auth/refresh', [VendorAuthController::class, 'refreshToken']); // Alias for frontend compatibility
    Route::get('/vendors/auth/profile', [VendorAuthController::class, 'getProfile']);
    Route::put('/vendors/auth/profile', [VendorAuthController::class, 'updateProfile']);
    Route::post('/vendors/auth/change-password', [VendorAuthController::class, 'changePassword']);

    // MRF routes
    Route::get('/mrfs/contract-types', [ContractTypeController::class, 'index']);
    Route::get('/mrfs', [MRFController::class, 'index']);
    Route::get('/mrfs/{id}', [MRFController::class, 'show']);
    Route::get('/mrfs/{id}/full-details', [MRFController::class, 'getFullDetails']); // Full MRF with all quotations
    Route::get('/mrfs/{id}/progress-tracker', [MRFController::class, 'getProgressTracker']); // Progress tracker
    Route::get('/mrfs/{id}/available-actions', [MRFController::class, 'getAvailableActions']);
    Route::get('/mrfs/{id}/line-item-pnl', [MRFController::class, 'lineItemProfitAndLoss']);
    Route::post('/mrfs', [MRFController::class, 'store']);
    Route::put('/mrfs/{id}', [MRFController::class, 'update']);
    Route::post('/mrfs/{id}/approve', [MRFController::class, 'approve']); // Legacy
    Route::post('/mrfs/{id}/reject', [MRFController::class, 'reject']); // Legacy
    Route::post('/mrfs/{id}/resubmit', [MRFController::class, 'resubmit']);
    Route::delete('/mrfs/{id}', [MRFController::class, 'destroy']);
    Route::post('/mrfs/{id}/executive-reject', [MRFController::class, 'executiveReject']);
    Route::post('/mrfs/{id}/supply-chain-director-reject', [MRFController::class, 'supplyChainDirectorReject']);

    // MRF Workflow routes (new simplified workflow)
    // NEW: Supply Chain Director is first approver
    Route::post('/mrfs/{id}/supply-chain-director-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'supplyChainDirectorApprove']);

    // NEW: Lazarus Director approval for high-value custom contract types (after Supply Chain Director)
    Route::post('/mrfs/{id}/lazarus-director-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'lazarusDirectorApprove']);

    // Procurement Manager approval (after Supply Chain Director)
    Route::post('/mrfs/{id}/procurement-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'procurementApprove']);

    // Legacy routes (for backward compatible with existing MRFs)
    Route::post('/mrfs/{id}/executive-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'executiveApprove']);
    Route::post('/mrfs/{id}/chairman-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'chairmanApprove']);

    // Vendor selection workflow routes
    Route::post('/mrfs/{id}/send-vendor-for-approval', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'sendVendorForApproval']);
    Route::post('/mrfs/{id}/approve-vendor-selection', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'approveVendorSelection']);
    Route::post('/mrfs/{id}/reject-vendor-selection', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'rejectVendorSelection']);
    Route::post('/mrfs/{id}/generate-po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'generatePO']);
    // Price comparison endpoints (read for SCD+, write for procurement)
    Route::get('/mrfs/{id}/price-comparisons', [PriceComparisonController::class, 'index']);
    Route::put('/mrfs/{id}/price-comparisons', [PriceComparisonController::class, 'bulkReplace']);
    Route::post('/mrfs/{id}/price-comparisons', [PriceComparisonController::class, 'bulkReplace']);
    Route::get('/mrfs/{id}/download-po', [\App\Http\Controllers\Api\MRFController::class, 'downloadPO']); // Download unsigned PO
    Route::get('/mrfs/{id}/download-signed-po', [\App\Http\Controllers\Api\MRFController::class, 'downloadSignedPO']); // Download signed PO
    Route::delete('/mrfs/{id}/po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'deletePO']); // Delete/clear PO
    Route::post('/mrfs/{id}/upload-signed-po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'uploadSignedPO']);
    Route::post('/purchase-orders/{po}/sign', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'signPurchaseOrder']);
    Route::post('/pos/{id}/close', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'close']);
    Route::post('/mrfs/{id}/reject-po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'rejectPO']);
    Route::post('/mrfs/{id}/process-payment', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'processPayment']);
    Route::post('/mrfs/{id}/approve-payment', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'approvePayment']);

    // GRN endpoints
    Route::get('/mrfs/{id}/workflow-gates', [\App\Http\Controllers\Api\WorkflowGateController::class, 'show']);
    Route::get('/mrfs/{id}/finance-sync', [\App\Http\Controllers\Api\FinanceSyncController::class, 'show']);
    Route::get('/mrfs/{id}/delivery-confirmation', [\App\Http\Controllers\Api\DeliveryConfirmationController::class, 'show']);
    Route::get('/mrfs/{id}/grn/prefill', [\App\Http\Controllers\Api\GRNController::class, 'prefillGrn']);
    Route::get('/mrfs/{id}/grn/preview', [\App\Http\Controllers\Api\GRNController::class, 'previewGrn']);
    Route::post('/mrfs/{id}/grn/preview', [\App\Http\Controllers\Api\GRNController::class, 'previewGrn']);
    Route::post('/mrfs/{id}/grn/generate', [\App\Http\Controllers\Api\GRNController::class, 'generateGrn']);
    Route::post('/mrfs/{id}/request-grn', [\App\Http\Controllers\Api\GRNController::class, 'requestGRN']);
    Route::post('/mrfs/{id}/complete-grn', [\App\Http\Controllers\Api\GRNController::class, 'completeGRN']);
    Route::get('/mrfs/{id}/procurement-documents', [\App\Http\Controllers\Api\ProcurementDocumentController::class, 'index']);
    Route::post('/mrfs/{id}/procurement-documents', [\App\Http\Controllers\Api\ProcurementDocumentController::class, 'store']);
    Route::get('/mrfs/{id}/payment-schedule', [\App\Http\Controllers\Api\PaymentScheduleController::class, 'show']);
    Route::post('/mrfs/{id}/payment-schedule', [\App\Http\Controllers\Api\PaymentScheduleController::class, 'store']);
    Route::put('/mrfs/{id}/payment-schedule', [\App\Http\Controllers\Api\PaymentScheduleController::class, 'update']);
    Route::get('/payment-term-templates', [\App\Http\Controllers\Api\PaymentScheduleController::class, 'templates']);

    // User management (admin only)
    Route::get('/users', [\App\Http\Controllers\Api\UserManagementController::class, 'index']);
    Route::get('/users/department-options', [\App\Http\Controllers\Api\UserManagementController::class, 'departmentOptions']);
    Route::post('/users', [\App\Http\Controllers\Api\UserManagementController::class, 'store']);
    Route::put('/users/{id}', [\App\Http\Controllers\Api\UserManagementController::class, 'update']);
    Route::delete('/users/{id}', [\App\Http\Controllers\Api\UserManagementController::class, 'destroy']);
    Route::post('/users/{id}/signature', [\App\Http\Controllers\Api\UserManagementController::class, 'uploadSignature']);
    Route::delete('/users/{id}/signature', [\App\Http\Controllers\Api\UserManagementController::class, 'deleteSignature']);
    Route::get('/departments/requisition-creators', [\App\Http\Controllers\Api\UserManagementController::class, 'listRequisitionCreators']);
    Route::put('/departments/{department}/requisition-creator', [\App\Http\Controllers\Api\UserManagementController::class, 'assignRequisitionCreator']);
    Route::post('/mrfs/{id}/workflow-reject', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'rejectMRF']);
    Route::post('/admin/backfill-vendor-profiles', [\App\Http\Controllers\Api\VendorController::class, 'backfillProfiles'])
    ->middleware(['auth:sanctum']);

    // SRF routes
    Route::get('/srfs', [SRFController::class, 'index']);
    Route::get('/srfs/{id}', [SRFController::class, 'show']);
    Route::get('/srfs/{id}/progress-tracker', [SRFController::class, 'progressTracker']);
    Route::get('/srfs/{id}/line-items/{itemId}', [SRFController::class, 'showLineItem']);
    Route::get('/srfs/{id}/line-item-pnl', [SRFController::class, 'lineItemProfitAndLoss']);
    Route::post('/srfs', [SRFController::class, 'store']);
    Route::put('/srfs/{id}', [SRFController::class, 'update']);
    Route::delete('/srfs/{id}', [SRFController::class, 'destroy']);
    Route::post('/srfs/{id}/supply-chain-director-approve', [SRFController::class, 'supplyChainDirectorApprove']);
    Route::post('/srfs/{id}/supply-chain-director-reject', [SRFController::class, 'supplyChainDirectorReject']);

    // RFQ routes
    Route::get('/rfqs', [RFQController::class, 'index']);
    Route::get('/rfqs/{id}', [RFQController::class, 'show']);
    Route::post('/rfqs', [RFQController::class, 'store']);
    Route::post('/rfqs/{id}/attachments', [RFQController::class, 'uploadAttachments']);
    Route::put('/rfqs/{id}', [RFQController::class, 'update']);
    Route::patch('/rfqs/{id}', [RFQController::class, 'update']);
    Route::get('/po-terms-templates/{type}', [POTermsTemplateController::class, 'show']);

    // RFQ Workflow routes (enhanced)
    Route::get('/vendors/rfqs', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'getVendorRFQs']); // Vendor portal
    Route::get('/vendor-portal/mrfs', [\App\Http\Controllers\Api\VendorPortalMrfController::class, 'index']);
    Route::get('/vendor-portal/mrfs/{mrfId}/invoice', [\App\Http\Controllers\Api\VendorPortalMrfController::class, 'showInvoiceStatus']);
    Route::post('/vendor-portal/mrfs/{mrfId}/invoice', [\App\Http\Controllers\Api\VendorPortalMrfController::class, 'submitInvoice']);
    Route::get('/vendors/portal/mrfs', [\App\Http\Controllers\Api\VendorPortalMrfController::class, 'index']);
    Route::get('/vendors/portal/mrfs/{mrfId}/invoice', [\App\Http\Controllers\Api\VendorPortalMrfController::class, 'showInvoiceStatus']);
    Route::post('/vendors/portal/mrfs/{mrfId}/invoice', [\App\Http\Controllers\Api\VendorPortalMrfController::class, 'submitInvoice']);
    // Assigned trips (logistics) — /vendors/* so clients that only attach Bearer for /api/vendors/* (same as RFQs) authenticate correctly
    Route::get('/vendors/assigned-trips', [LogisticsVendorPortalTripController::class, 'indexVendorTrips']);
    Route::post('/vendors/portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'submitVendorDetails']);
    Route::get('/vendors/portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'getVendorSubmission']);
    Route::post('/vendors/portal/trips/{tripId}/documents', [LogisticsVendorPortalTripController::class, 'uploadDocuments']);
    Route::post('/rfqs/{id}/mark-viewed', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'markAsViewed']); // Vendor marks as viewed
    Route::post('/rfqs/{id}/submit-quotation', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'submitQuotation']); // Submit quotation for RFQ
    Route::get('/rfqs/{id}/quotations', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'getQuotationsForRFQ']); // Comparison view
    Route::post('/rfqs/{id}/select-vendor', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'selectVendor']); // Award RFQ
    Route::post('/rfqs/{id}/close', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'closeRFQ']); // Close without award

    // Quotation routes
    Route::get('/quotations', [QuotationController::class, 'index']);
    Route::get('/quotations/rfq/{rfqId}', [QuotationController::class, 'byRfq']);
    Route::get('/quotations/{id}', [QuotationController::class, 'show']);
    Route::put('/quotations/{id}/evaluation', [QuotationController::class, 'saveEvaluation']);
    Route::patch('/quotations/{id}/evaluation', [QuotationController::class, 'saveEvaluation']);
    Route::post('/quotations', [QuotationController::class, 'store']);
    Route::delete('/quotations/{id}', [QuotationController::class, 'destroy']); // Vendor can delete their own quotations
    Route::post('/quotations/{id}/approve', [QuotationController::class, 'approve']);
    Route::post('/quotations/{id}/reject', [QuotationController::class, 'reject']);
    Route::post('/quotations/{id}/request-revision', [QuotationController::class, 'requestRevision']);
    Route::post('/quotations/{id}/close', [QuotationController::class, 'close']);
    Route::post('/quotations/{id}/reopen', [QuotationController::class, 'reopen']);

    // Vendor routes - specific routes must come before parameterized routes
    Route::get('/vendors', [VendorController::class, 'index']);
    Route::get('/vendors/export', [VendorController::class, 'exportDirectory']);
    Route::get('/vendors/export/rows', [VendorController::class, 'exportDirectoryRows']);
    Route::get('/vendors/export/columns', [VendorController::class, 'exportDirectoryColumns']);
    Route::get('/vendors/lookup', [VendorController::class, 'lookup']);
    Route::post('/vendors/bulk-delete', [VendorController::class, 'bulkDestroy']);
    Route::get('/vendors/quotations', [VendorController::class, 'getVendorQuotations']);
    Route::delete('/vendors/quotations/{id}', [QuotationController::class, 'destroy']); // Vendor can delete their own quotations
    Route::delete('/vendors/{id}', [VendorController::class, 'destroy']);
    Route::post('/vendors/invite', [VendorController::class, 'inviteVendor']);
    Route::post('/vendors/{id}/rating', [VendorController::class, 'addRating']);
    Route::get('/vendors/{id}/comments', [VendorController::class, 'getComments']);
    Route::get('/vendors/registrations', [VendorController::class, 'registrations']);
    Route::get('/vendors/registrations/{id}', [VendorController::class, 'getRegistration']);
    Route::get('/vendors/{id}', [VendorController::class, 'show']);
    Route::get('/vendors/registrations/{registrationId}/documents/{documentId}/download', [VendorController::class, 'downloadDocument']);
    Route::get('/vendors/documents/expiring', [VendorController::class, 'getExpiringDocuments']);
    Route::post('/vendors/registrations/{id}/approve', [VendorController::class, 'approveRegistration']);
    Route::post('/vendors/registrations/{id}/reject', [VendorController::class, 'rejectRegistration']);
    Route::put('/vendors/{id}/credentials', [VendorController::class, 'updateVendorCredentials']);
    Route::put('/vendors/{uuid}', [VendorController::class, 'adminUpdate'])->middleware('role:procurement_manager,supply_chain_director');

    // Dashboard routes
    Route::get('/dashboard/kpis', [DashboardKpiController::class, 'index']);
    Route::get('/dashboard/procurement-manager', [DashboardController::class, 'procurementManagerDashboard']);
    Route::get('/dashboard/logistics-statistics', [LogisticsDashboardController::class, 'stats'])->middleware('role:procurement_manager,logistics_manager,logistics_officer,supply_chain_director,admin,executive,chairman,finance');
    Route::get('/dashboard/supply-chain-director', [DashboardController::class, 'supplyChainDirectorDashboard']);
    Route::get('/dashboard/executive', [DashboardController::class, 'executiveDashboard']);
    Route::get('/dashboard/vendor', [DashboardController::class, 'vendorDashboard']);
    Route::get('/dashboard/finance', [DashboardController::class, 'financeDashboard']);
    Route::get('/dashboard/recent-activities', [DashboardController::class, 'getRecentActivities']);

    // Notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/statistics', [NotificationController::class, 'statistics']);
    Route::get('/notifications/{id}', [NotificationController::class, 'show']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::match(['post', 'put'], '/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
    Route::post('/notifications/announcement', [NotificationController::class, 'sendAnnouncement']);

    // Procurement reporting
    Route::get('/reports/dashboard', [ReportsDashboardController::class, 'index']);
    Route::get('/reports/procurement', [ProcurementReportController::class, 'index']);
    Route::get('/reports/procurement/export', [ProcurementReportController::class, 'export']);
    Route::get('/reports/procurement/records', [ReportingEngineController::class, 'procurementRecords']);
    Route::get('/reports/procurement/records/export', [ReportingEngineController::class, 'exportProcurementRecords']);
    Route::get('/reports/procurement/records/{id}', [ReportingEngineController::class, 'procurementRecordDetail']);
    Route::get('/reports/finance-ap/summary', [FinanceApReportController::class, 'summary']);
    Route::get('/reports/finance-ap/outstanding-milestones', [FinanceApReportController::class, 'outstandingMilestones']);
    Route::get('/reports/finance-ap/advance-delivery-risk', [FinanceApReportController::class, 'advanceDeliveryRisk']);
    Route::get('/reports/finance-ap/cycle-times', [FinanceApReportController::class, 'cycleTimes']);
    Route::get('/reports/finance-ap/sync-events', [FinanceApReportController::class, 'syncEvents']);
    Route::get('/config/finance-routing', [AppConfigController::class, 'financeRouting']);

    // Eligible passengers / drivers for trip scheduling (excludes vendors & power users)
    Route::get('/users/eligible-passengers', [EligiblePassengersController::class, 'index']);

    // Global search (supports formatted_id + legacy ids)
    Route::get('/search', [SearchController::class, 'search']);

    // Admin mappings (optional; avoids redeploy for new codes)
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/department-codes', [CodeMappingsController::class, 'listDepartmentCodes']);
        Route::post('/admin/department-codes', [CodeMappingsController::class, 'createDepartmentCode']);
        Route::get('/admin/category-codes', [CodeMappingsController::class, 'listCategoryCodes']);
        Route::post('/admin/category-codes', [CodeMappingsController::class, 'createCategoryCode']);
    });
});

Route::options('/vendors/register', function() {
    return response('', 204);
});

// Public vendor registration
Route::get('/vendors/categories', [VendorController::class, 'categories']);
Route::post('/vendors/register', [VendorController::class, 'register']);

// ===============================
// Logistics Module v1 (versioned)
// ===============================
Route::prefix('v1/logistics')->group(function () {
    $logisticsInternalRoles = 'role:procurement_manager,logistics_manager,logistics_officer,supply_chain_director,admin,executive,chairman,finance';
    // Public auth endpoints
    Route::post('/auth/login', [LogisticsAuthController::class, 'login']);
    Route::post('/auth/vendor-accept', [LogisticsAuthController::class, 'vendorAccept']);
    Route::get('/docs', [LogisticsDocsController::class, 'ui']);
    Route::get('/openapi.yaml', [LogisticsDocsController::class, 'spec']);

    Route::middleware('auth:sanctum')->group(function () use ($logisticsInternalRoles) {
        Route::get('/auth/me', [LogisticsAuthController::class, 'me']);
        Route::post('/auth/vendor-invite', [LogisticsAuthController::class, 'vendorInvite'])->middleware($logisticsInternalRoles);
        Route::get('/dashboard/stats', [LogisticsDashboardController::class, 'stats'])->middleware($logisticsInternalRoles);

        // Vendor Management
        Route::post('/vendors', [LogisticsVendorController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/vendors', [LogisticsVendorController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/vendors/{id}', [LogisticsVendorController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/vendors/{id}', [LogisticsVendorController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/vendors/{id}/invite', [LogisticsVendorController::class, 'invite'])->middleware($logisticsInternalRoles);

        // Trip Management
        Route::post('/trips', [LogisticsTripController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/trips', [LogisticsTripController::class, 'index']);
        Route::get('/trips/{id}', [LogisticsTripController::class, 'show']);
        Route::post('/trips/{id}/request-changes', [TripRequestWorkflowController::class, 'requestChanges'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/director-approve', [TripRequestWorkflowController::class, 'directorApprove']);
    Route::post('/trips/{id}/director-reject', [TripRequestWorkflowController::class, 'directorReject']);
    Route::post('/trips/{id}/director-return', [TripRequestWorkflowController::class, 'directorReturn']);
        Route::put('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::patch('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/cancel', [LogisticsTripController::class, 'cancel'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/assign-resources', [LogisticsTripController::class, 'assignResources'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{id}/comments', [LogisticsTripController::class, 'getComments']);
        Route::post('/trips/{id}/comments', [LogisticsTripController::class, 'addComment']);
        Route::post('/trips/bulk-upload', [LogisticsTripController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);

        // Journey Management
        Route::post('/journeys', [LogisticsJourneyController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/journeys', [LogisticsJourneyController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/journeys/{trip_id}', [LogisticsJourneyController::class, 'listByTrip'])->middleware($logisticsInternalRoles);
        Route::put('/journeys/{id}', [LogisticsJourneyController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/journeys/{id}/update-status', [LogisticsJourneyController::class, 'updateStatus'])->middleware('role:vendor,logistics_officer,logistics_manager,procurement_manager,supply_chain_director,admin');

        // Trip Vendor Submission & Multi-Vendor Workflow
        Route::post('/trips/{tripId}/invite-vendors', [LogisticsTripVendorSubmissionController::class, 'inviteVendors'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/vendor-responses', [LogisticsTripVendorSubmissionController::class, 'getVendorResponses'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/select-vendor', [LogisticsTripVendorSubmissionController::class, 'selectVendor'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/submission', [LogisticsTripVendorSubmissionController::class, 'listTripSubmissions'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/vendor-submission/{submissionId}', [LogisticsTripVendorSubmissionController::class, 'getSubmission'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/route-to-procurement', [LogisticsTripVendorSubmissionController::class, 'routeToProcurement'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/notify-invoice', [LogisticsTripVendorSubmissionController::class, 'notifyInvoiceSubmission'])->middleware($logisticsInternalRoles);

        // Vendor Portal Trip Endpoints
        Route::get('/vendor-portal/trips', [LogisticsVendorPortalTripController::class, 'indexVendorTrips']);
        Route::post('/vendor-portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'submitVendorDetails']);
        Route::post('/vendor-portal/trips/{tripId}/documents', [LogisticsVendorPortalTripController::class, 'uploadDocuments']);
        Route::get('/vendor-portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'getVendorSubmission']);

        // Accommodation Bookings
        Route::post('/accommodations', [LogisticsAccommodationBookingController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/accommodations', [LogisticsAccommodationBookingController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::patch('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/accommodations', [LogisticsAccommodationBookingController::class, 'getTripAccommodations'])->middleware($logisticsInternalRoles);
        Route::post('/accommodations/{id}/add-passenger', [LogisticsAccommodationBookingController::class, 'addPassenger'])->middleware($logisticsInternalRoles);
        Route::post('/accommodations/{id}/remove-passenger', [LogisticsAccommodationBookingController::class, 'removePassenger'])->middleware($logisticsInternalRoles);

        // Job Completion Certificates
        Route::post('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::patch('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/line-items', [LogisticsJobCompletionCertificateController::class, 'addLineItem'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{tripId}/jcc/line-items/{lineItemId}', [LogisticsJobCompletionCertificateController::class, 'updateLineItem'])->middleware($logisticsInternalRoles);
        Route::delete('/trips/{tripId}/jcc/line-items/{lineItemId}', [LogisticsJobCompletionCertificateController::class, 'deleteLineItem'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/jcc/prefill', [LogisticsJobCompletionCertificateController::class, 'prefillSuggestions'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/prefill', [LogisticsJobCompletionCertificateController::class, 'prefill'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/submit', [LogisticsJobCompletionCertificateController::class, 'submit'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/approve', [LogisticsJobCompletionCertificateController::class, 'approve'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/jcc/pdf', [LogisticsJobCompletionCertificateController::class, 'generatePdf'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/jcc/pdf/layout', [LogisticsJobCompletionCertificateController::class, 'getPdfLayout'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/attachments', [LogisticsJobCompletionCertificateController::class, 'uploadAttachment'])->middleware($logisticsInternalRoles);
        Route::get('/jcc', [LogisticsJobCompletionCertificateController::class, 'index'])->middleware($logisticsInternalRoles);

        // Fleet Management
        Route::post('/fleet/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/vehicles/{vehicleId}/documents', [LogisticsFleetVehicleDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{vehicleId}/documents', [LogisticsFleetVehicleDocumentController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/vehicles/{vehicleId}/documents/{documentId}', [LogisticsFleetVehicleDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{vehicleId}/maintenance', [LogisticsFleetController::class, 'listMaintenance'])->middleware($logisticsInternalRoles);
        Route::patch('/fleet/vehicles/{vehicleId}/maintenance/{scheduleId}', [LogisticsFleetController::class, 'updateMaintenance'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/maintenance/upcoming', [LogisticsFleetController::class, 'upcomingMaintenance'])->middleware($logisticsInternalRoles);
        Route::patch('/fleet/vehicles/{id}/status', [LogisticsFleetController::class, 'updateVehicleStatus'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/drivers', [LogisticsFleetDriverController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/drivers', [LogisticsFleetDriverController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::patch('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/drivers/{id}/assign', [LogisticsFleetDriverController::class, 'assign'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/drivers/{driverId}/documents/{documentId}', [LogisticsFleetDriverDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::get('/trip-requests/booking-rules', [TripRequestWorkflowController::class, 'bookingRules']);
        Route::get('/trip-requests/all', [TripRequestWorkflowController::class, 'allTrips']);
        Route::get('/trip-requests', [TripRequestWorkflowController::class, 'index']);
        Route::post('/trip-requests', [TripRequestWorkflowController::class, 'store']);
        Route::put('/trip-requests/{id}', [TripRequestWorkflowController::class, 'update']);
        Route::post('/trip-requests/{id}/confirm', [TripRequestWorkflowController::class, 'confirm']);
        Route::post('/trip-requests/{id}/forward', [TripRequestWorkflowController::class, 'forward']);
        Route::post('/trip-requests/{id}/request-changes', [TripRequestWorkflowController::class, 'requestChanges']);
        Route::post('/trip-requests/{id}/director-approve', [TripRequestWorkflowController::class, 'directorApprove']);
        Route::post('/trip-requests/{id}/director-reject', [TripRequestWorkflowController::class, 'directorReject']);
        Route::post('/trip-requests/{id}/director-return', [TripRequestWorkflowController::class, 'directorReturn']);
        Route::post('/trip-requests/{id}/convert', [TripRequestWorkflowController::class, 'convert']);
        Route::post('/trip-requests/{id}/reject', [TripRequestWorkflowController::class, 'reject']);
        Route::get('/trip-requests/{id}/comments', [TripRequestWorkflowController::class, 'getComments']);
        Route::post('/trip-requests/{id}/comments', [TripRequestWorkflowController::class, 'addComment']);
        Route::get('/trip-requests/{id}', [TripRequestWorkflowController::class, 'show']);
        Route::get('/trip-requests/{id}/progress-tracker', [TripRequestWorkflowController::class, 'progressTracker']);
        Route::delete('/trip-requests/{id}', [TripRequestWorkflowController::class, 'destroy']);
        Route::post('/trips/{id}/convert-to-logistics-request', [TripRequestWorkflowController::class, 'convertToLogisticsRequest'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/procurement-approve-quote', [TripRequestWorkflowController::class, 'procurementApproveQuote'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/scd-approve', [TripRequestWorkflowController::class, 'scdApprove'])->middleware('role:supply_chain_director,supply_chain,admin');
        Route::post('/trips/{id}/generate-trip-po', [TripRequestWorkflowController::class, 'generatePo'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/upload-signed-trip-po', [TripRequestWorkflowController::class, 'uploadSignedPo'])->middleware('role:supply_chain_director,supply_chain,admin');
        Route::post('/fleet/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/vehicles/{id}/initiate-srf', [LogisticsFleetController::class, 'initiateSrf'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/alerts', [LogisticsFleetController::class, 'getAlerts'])->middleware('auth:sanctum');

        // Compatibility aliases for older clients (vehicles without /fleet prefix)
        Route::post('/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
        Route::post('/vehicles/{id}/initiate-srf', [LogisticsFleetController::class, 'initiateSrf'])->middleware($logisticsInternalRoles);

        // Document Management
        Route::post('/documents', [LogisticsDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/documents/{entity_type}/{entity_id}', [LogisticsDocumentController::class, 'list'])->middleware($logisticsInternalRoles);
        Route::delete('/documents/{id}', [LogisticsDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);

        // Notifications
        Route::post('/notifications/send', [LogisticsNotificationController::class, 'send'])->middleware($logisticsInternalRoles);
        Route::get('/notifications', [LogisticsNotificationController::class, 'index'])->middleware($logisticsInternalRoles);

        // Reports
        Route::post('/reports', [LogisticsReportController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/reports', [LogisticsReportController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/reports/pending', [LogisticsReportController::class, 'pending'])->middleware($logisticsInternalRoles);

        // Bulk Upload Templates
        Route::get('/uploads/templates', [LogisticsUploadController::class, 'templates'])->middleware($logisticsInternalRoles);
    });
});

// Logistics Module (compatibility aliases for existing frontend)
Route::prefix('logistics')->group(function () {
    $logisticsInternalRoles = 'role:procurement_manager,logistics_manager,logistics_officer,supply_chain_director,admin,executive,chairman,finance';

    Route::post('/auth/login', [LogisticsAuthController::class, 'login']);
    Route::post('/auth/vendor-accept', [LogisticsAuthController::class, 'vendorAccept']);
    Route::get('/docs', [LogisticsDocsController::class, 'ui']);
    Route::get('/openapi.yaml', [LogisticsDocsController::class, 'spec']);

    Route::middleware('auth:sanctum')->group(function () use ($logisticsInternalRoles) {
        Route::get('/auth/me', [LogisticsAuthController::class, 'me']);
        Route::post('/auth/vendor-invite', [LogisticsAuthController::class, 'vendorInvite'])->middleware($logisticsInternalRoles);
        Route::get('/dashboard/stats', [LogisticsDashboardController::class, 'stats'])->middleware($logisticsInternalRoles);

        Route::post('/vendors', [LogisticsVendorController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/vendors', [LogisticsVendorController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/vendors/{id}', [LogisticsVendorController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/vendors/{id}', [LogisticsVendorController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/vendors/{id}/invite', [LogisticsVendorController::class, 'invite'])->middleware($logisticsInternalRoles);

        Route::post('/trips', [LogisticsTripController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/trips', [LogisticsTripController::class, 'index']);
        Route::get('/trips/{id}', [LogisticsTripController::class, 'show']);
        Route::post('/trips/{id}/request-changes', [TripRequestWorkflowController::class, 'requestChanges'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/director-approve', [TripRequestWorkflowController::class, 'directorApprove']);
    Route::post('/trips/{id}/director-reject', [TripRequestWorkflowController::class, 'directorReject']);
    Route::post('/trips/{id}/director-return', [TripRequestWorkflowController::class, 'directorReturn']);
        Route::put('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::patch('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/cancel', [LogisticsTripController::class, 'cancel'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/assign-resources', [LogisticsTripController::class, 'assignResources'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{id}/comments', [LogisticsTripController::class, 'getComments']);
        Route::post('/trips/{id}/comments', [LogisticsTripController::class, 'addComment']);
        Route::post('/trips/bulk-upload', [LogisticsTripController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);

        Route::post('/trips/{tripId}/invite-vendors', [LogisticsTripVendorSubmissionController::class, 'inviteVendors'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/vendor-responses', [LogisticsTripVendorSubmissionController::class, 'getVendorResponses'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/select-vendor', [LogisticsTripVendorSubmissionController::class, 'selectVendor'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/submission', [LogisticsTripVendorSubmissionController::class, 'listTripSubmissions'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/vendor-submission/{submissionId}', [LogisticsTripVendorSubmissionController::class, 'getSubmission'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/route-to-procurement', [LogisticsTripVendorSubmissionController::class, 'routeToProcurement'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/notify-invoice', [LogisticsTripVendorSubmissionController::class, 'notifyInvoiceSubmission'])->middleware($logisticsInternalRoles);

        Route::get('/vendor-portal/trips', [LogisticsVendorPortalTripController::class, 'indexVendorTrips']);
        Route::post('/vendor-portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'submitVendorDetails']);
        Route::post('/vendor-portal/trips/{tripId}/documents', [LogisticsVendorPortalTripController::class, 'uploadDocuments']);
        Route::get('/vendor-portal/trips/{tripId}/submission', [LogisticsVendorPortalTripController::class, 'getVendorSubmission']);

        Route::post('/accommodations', [LogisticsAccommodationBookingController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/accommodations', [LogisticsAccommodationBookingController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::patch('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/accommodations/{id}', [LogisticsAccommodationBookingController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/accommodations', [LogisticsAccommodationBookingController::class, 'getTripAccommodations'])->middleware($logisticsInternalRoles);

        Route::post('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::patch('/trips/{tripId}/jcc', [LogisticsJobCompletionCertificateController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/line-items', [LogisticsJobCompletionCertificateController::class, 'addLineItem'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{tripId}/jcc/line-items/{lineItemId}', [LogisticsJobCompletionCertificateController::class, 'updateLineItem'])->middleware($logisticsInternalRoles);
        Route::delete('/trips/{tripId}/jcc/line-items/{lineItemId}', [LogisticsJobCompletionCertificateController::class, 'deleteLineItem'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/jcc/prefill', [LogisticsJobCompletionCertificateController::class, 'prefillSuggestions'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/prefill', [LogisticsJobCompletionCertificateController::class, 'prefill'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/submit', [LogisticsJobCompletionCertificateController::class, 'submit'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{tripId}/jcc/approve', [LogisticsJobCompletionCertificateController::class, 'approve'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{tripId}/jcc/pdf', [LogisticsJobCompletionCertificateController::class, 'generatePdf'])->middleware($logisticsInternalRoles);

        Route::post('/journeys', [LogisticsJourneyController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/journeys', [LogisticsJourneyController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/journeys/{trip_id}', [LogisticsJourneyController::class, 'listByTrip'])->middleware($logisticsInternalRoles);
        Route::put('/journeys/{id}', [LogisticsJourneyController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/journeys/{id}/update-status', [LogisticsJourneyController::class, 'updateStatus'])->middleware('role:vendor,logistics_officer,logistics_manager,procurement_manager,supply_chain_director,admin');

        Route::get('/materials/summary', [LogisticsMaterialController::class, 'summary'])->middleware($logisticsInternalRoles);
        Route::get('/materials-summary', [LogisticsMaterialController::class, 'summary'])->middleware($logisticsInternalRoles);
        Route::post('/materials/bulk-upload', [LogisticsMaterialController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);
        Route::post('/materials', [LogisticsMaterialController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/materials', [LogisticsMaterialController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/materials/{id}', [LogisticsMaterialController::class, 'show'])->middleware($logisticsInternalRoles)->where('id', '[0-9]+');
        Route::delete('/materials/{id}', [LogisticsMaterialController::class, 'destroy'])->middleware($logisticsInternalRoles)->where('id', '[0-9]+');
        Route::get('/trips/{id}/materials', [LogisticsMaterialController::class, 'listByTrip'])->middleware($logisticsInternalRoles);

        Route::post('/fleet/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/vehicles/{vehicleId}/documents', [LogisticsFleetVehicleDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{vehicleId}/documents', [LogisticsFleetVehicleDocumentController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/vehicles/{vehicleId}/documents/{documentId}', [LogisticsFleetVehicleDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{vehicleId}/maintenance', [LogisticsFleetController::class, 'listMaintenance'])->middleware($logisticsInternalRoles);
        Route::patch('/fleet/vehicles/{vehicleId}/maintenance/{scheduleId}', [LogisticsFleetController::class, 'updateMaintenance'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/maintenance/upcoming', [LogisticsFleetController::class, 'upcomingMaintenance'])->middleware($logisticsInternalRoles);
        Route::patch('/fleet/vehicles/{id}/status', [LogisticsFleetController::class, 'updateVehicleStatus'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/drivers', [LogisticsFleetDriverController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/drivers', [LogisticsFleetDriverController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::patch('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/drivers/{id}', [LogisticsFleetDriverController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/drivers/{id}/assign', [LogisticsFleetDriverController::class, 'assign'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/drivers/{driverId}/documents/{documentId}', [LogisticsFleetDriverDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/drivers/{driverId}/documents', [LogisticsFleetDriverDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::get('/trip-requests/booking-rules', [TripRequestWorkflowController::class, 'bookingRules']);
        Route::get('/trip-requests/all', [TripRequestWorkflowController::class, 'allTrips']);
        Route::get('/trip-requests', [TripRequestWorkflowController::class, 'index']);
        Route::post('/trip-requests', [TripRequestWorkflowController::class, 'store']);
        Route::put('/trip-requests/{id}', [TripRequestWorkflowController::class, 'update']);
        Route::post('/trip-requests/{id}/confirm', [TripRequestWorkflowController::class, 'confirm']);
        Route::post('/trip-requests/{id}/forward', [TripRequestWorkflowController::class, 'forward']);
        Route::post('/trip-requests/{id}/request-changes', [TripRequestWorkflowController::class, 'requestChanges']);
        Route::post('/trip-requests/{id}/director-approve', [TripRequestWorkflowController::class, 'directorApprove']);
        Route::post('/trip-requests/{id}/director-reject', [TripRequestWorkflowController::class, 'directorReject']);
        Route::post('/trip-requests/{id}/director-return', [TripRequestWorkflowController::class, 'directorReturn']);
        Route::post('/trip-requests/{id}/convert', [TripRequestWorkflowController::class, 'convert']);
        Route::post('/trip-requests/{id}/reject', [TripRequestWorkflowController::class, 'reject']);
        Route::get('/trip-requests/{id}/comments', [TripRequestWorkflowController::class, 'getComments']);
        Route::post('/trip-requests/{id}/comments', [TripRequestWorkflowController::class, 'addComment']);
        Route::get('/trip-requests/{id}', [TripRequestWorkflowController::class, 'show']);
        Route::get('/trip-requests/{id}/progress-tracker', [TripRequestWorkflowController::class, 'progressTracker']);
        Route::delete('/trip-requests/{id}', [TripRequestWorkflowController::class, 'destroy']);
        Route::post('/trips/{id}/convert-to-logistics-request', [TripRequestWorkflowController::class, 'convertToLogisticsRequest'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/procurement-approve-quote', [TripRequestWorkflowController::class, 'procurementApproveQuote'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/scd-approve', [TripRequestWorkflowController::class, 'scdApprove'])->middleware('role:supply_chain_director,supply_chain,admin');
        Route::post('/trips/{id}/generate-trip-po', [TripRequestWorkflowController::class, 'generatePo'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/upload-signed-trip-po', [TripRequestWorkflowController::class, 'uploadSignedPo'])->middleware('role:supply_chain_director,supply_chain,admin');
        Route::post('/fleet/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/vehicles/{id}/initiate-srf', [LogisticsFleetController::class, 'initiateSrf'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/alerts', [LogisticsFleetController::class, 'getAlerts'])->middleware('auth:sanctum');

        // Same /vehicles/* aliases as v1/logistics — some frontends call /api/logistics/vehicles/... only.
        Route::post('/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
        Route::post('/vehicles/{id}/initiate-srf', [LogisticsFleetController::class, 'initiateSrf'])->middleware($logisticsInternalRoles);

        Route::post('/documents', [LogisticsDocumentController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/documents/{entity_type}/{entity_id}', [LogisticsDocumentController::class, 'list'])->middleware($logisticsInternalRoles);
        Route::delete('/documents/{id}', [LogisticsDocumentController::class, 'destroy'])->middleware($logisticsInternalRoles);

        Route::post('/notifications/send', [LogisticsNotificationController::class, 'send'])->middleware($logisticsInternalRoles);
        Route::get('/notifications', [LogisticsNotificationController::class, 'index'])->middleware($logisticsInternalRoles);

        Route::post('/reports', [LogisticsReportController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/reports', [LogisticsReportController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/reports/pending', [LogisticsReportController::class, 'pending'])->middleware($logisticsInternalRoles);

        Route::get('/uploads/templates', [LogisticsUploadController::class, 'templates'])->middleware($logisticsInternalRoles);
    });
});
