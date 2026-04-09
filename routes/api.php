<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VendorAuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MRFController;
use App\Http\Controllers\Api\SRFController;
use App\Http\Controllers\Api\RFQController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\V1\Logistics\AuthController as LogisticsAuthController;
use App\Http\Controllers\Api\V1\Logistics\VendorController as LogisticsVendorController;
use App\Http\Controllers\Api\V1\Logistics\TripController as LogisticsTripController;
use App\Http\Controllers\Api\V1\Logistics\JourneyController as LogisticsJourneyController;
use App\Http\Controllers\Api\V1\Logistics\MaterialController as LogisticsMaterialController;
use App\Http\Controllers\Api\V1\Logistics\FleetController as LogisticsFleetController;
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

// Public routes
Route::get('/cors-test', function () {
    return response()->json([
        'success' => true,
        'message' => 'CORS is working correctly',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/vendor/change-password', [AuthController::class, 'forcePasswordChange']);

// Vendor authentication (public)
Route::post('/vendors/auth/login', [VendorAuthController::class, 'login']);
Route::post('/vendors/auth/password-reset', [VendorAuthController::class, 'requestPasswordReset']);

// ======================================
// Backward-compatibility routes at /api/
// Logistics endpoints at simple /api/ paths
// ======================================
Route::middleware('auth:sanctum')->group(function () {
    $logisticsInternalRoles = 'role:procurement_manager,logistics_manager,supply_chain_director,admin,executive,chairman,finance';
    
    // Trip routes - forward to logistics controllers
    Route::post('/trips', [LogisticsTripController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/trips', [LogisticsTripController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/trips/{id}', [LogisticsTripController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::patch('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
    Route::put('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
    Route::post('/trips/{id}/cancel', [LogisticsTripController::class, 'cancel'])->middleware($logisticsInternalRoles);
    Route::post('/trips/bulk-upload', [LogisticsTripController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);
    
    // Journey routes - forward to logistics controllers
    Route::post('/journeys', [LogisticsJourneyController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/journeys/{trip_id}', [LogisticsJourneyController::class, 'listByTrip'])->middleware($logisticsInternalRoles);
    Route::put('/journeys/{id}', [LogisticsJourneyController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::post('/journeys/{id}/update-status', [LogisticsJourneyController::class, 'updateStatus'])->middleware('role:vendor,logistics_manager,procurement_manager,supply_chain_director,admin');
    
    // Fleet routes - forward to logistics controllers
    Route::post('/fleet/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::delete('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::post('/fleet/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
    Route::get('/fleet/alerts', [LogisticsFleetController::class, 'getAlerts'])->middleware('auth:sanctum');

    // Compatibility aliases for older clients (vehicles without /fleet prefix)
    Route::post('/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::put('/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
    Route::delete('/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::post('/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
    
    // Materials routes - forward to logistics controllers
    Route::post('/materials', [LogisticsMaterialController::class, 'store'])->middleware($logisticsInternalRoles);
    Route::get('/materials', [LogisticsMaterialController::class, 'index'])->middleware($logisticsInternalRoles);
    Route::get('/materials/{id}', [LogisticsMaterialController::class, 'show'])->middleware($logisticsInternalRoles);
    Route::delete('/materials/{id}', [LogisticsMaterialController::class, 'destroy'])->middleware($logisticsInternalRoles);
    Route::post('/materials/bulk-upload', [LogisticsMaterialController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);
    
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
                'role' => $user->role,
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
    Route::get('/mrfs', [MRFController::class, 'index']);
    Route::get('/mrfs/{id}', [MRFController::class, 'show']);
    Route::get('/mrfs/{id}/full-details', [MRFController::class, 'getFullDetails']); // Full MRF with all quotations
    Route::get('/mrfs/{id}/progress-tracker', [MRFController::class, 'getProgressTracker']); // Progress tracker
    Route::get('/mrfs/{id}/available-actions', [MRFController::class, 'getAvailableActions']);
    Route::post('/mrfs', [MRFController::class, 'store']);
    Route::put('/mrfs/{id}', [MRFController::class, 'update']);
    Route::post('/mrfs/{id}/approve', [MRFController::class, 'approve']); // Legacy
    Route::post('/mrfs/{id}/reject', [MRFController::class, 'reject']); // Legacy
    Route::delete('/mrfs/{id}', [MRFController::class, 'destroy']);
    Route::post('/mrfs/{id}/executive-reject', [MRFController::class, 'executiveReject']);
    
    // MRF Workflow routes (new simplified workflow)
    // NEW: Supply Chain Director is first approver
    Route::post('/mrfs/{id}/supply-chain-director-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'supplyChainDirectorApprove']);
    
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
    Route::get('/mrfs/{id}/download-po', [\App\Http\Controllers\Api\MRFController::class, 'downloadPO']); // Download unsigned PO
    Route::get('/mrfs/{id}/download-signed-po', [\App\Http\Controllers\Api\MRFController::class, 'downloadSignedPO']); // Download signed PO
    Route::delete('/mrfs/{id}/po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'deletePO']); // Delete/clear PO
    Route::post('/mrfs/{id}/upload-signed-po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'uploadSignedPO']);
    Route::post('/mrfs/{id}/reject-po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'rejectPO']);
    Route::post('/mrfs/{id}/process-payment', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'processPayment']);
    Route::post('/mrfs/{id}/approve-payment', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'approvePayment']);
    
    // GRN endpoints
    Route::post('/mrfs/{id}/request-grn', [\App\Http\Controllers\Api\GRNController::class, 'requestGRN']);
    Route::post('/mrfs/{id}/complete-grn', [\App\Http\Controllers\Api\GRNController::class, 'completeGRN']);
    
    // User management (admin only)
    Route::get('/users', [\App\Http\Controllers\Api\UserManagementController::class, 'index']);
    Route::post('/users', [\App\Http\Controllers\Api\UserManagementController::class, 'store']);
    Route::put('/users/{id}', [\App\Http\Controllers\Api\UserManagementController::class, 'update']);
    Route::delete('/users/{id}', [\App\Http\Controllers\Api\UserManagementController::class, 'destroy']);
    Route::post('/mrfs/{id}/workflow-reject', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'rejectMRF']);

    // SRF routes
    Route::get('/srfs', [SRFController::class, 'index']);
    Route::post('/srfs', [SRFController::class, 'store']);
    Route::put('/srfs/{id}', [SRFController::class, 'update']);

    // RFQ routes
    Route::get('/rfqs', [RFQController::class, 'index']);
    Route::post('/rfqs', [RFQController::class, 'store']);
    Route::put('/rfqs/{id}', [RFQController::class, 'update']);
    
    // RFQ Workflow routes (enhanced)
    Route::get('/vendors/rfqs', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'getVendorRFQs']); // Vendor portal
    Route::post('/rfqs/{id}/mark-viewed', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'markAsViewed']); // Vendor marks as viewed
    Route::post('/rfqs/{id}/submit-quotation', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'submitQuotation']); // Submit quotation for RFQ
    Route::get('/rfqs/{id}/quotations', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'getQuotationsForRFQ']); // Comparison view
    Route::post('/rfqs/{id}/select-vendor', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'selectVendor']); // Award RFQ
    Route::post('/rfqs/{id}/close', [\App\Http\Controllers\Api\RFQWorkflowController::class, 'closeRFQ']); // Close without award

    // Quotation routes
    Route::get('/quotations', [QuotationController::class, 'index']);
    Route::post('/quotations', [QuotationController::class, 'store']);
    Route::delete('/quotations/{id}', [QuotationController::class, 'destroy']); // Vendor can delete their own quotations
    Route::post('/quotations/{id}/approve', [QuotationController::class, 'approve']);
    Route::post('/quotations/{id}/reject', [QuotationController::class, 'reject']);
    Route::post('/quotations/{id}/request-revision', [QuotationController::class, 'requestRevision']);
    Route::post('/quotations/{id}/close', [QuotationController::class, 'close']);
    Route::post('/quotations/{id}/reopen', [QuotationController::class, 'reopen']);

    // Vendor routes - specific routes must come before parameterized routes
    Route::get('/vendors', [VendorController::class, 'index']);
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

    // Dashboard routes
    Route::get('/dashboard/procurement-manager', [DashboardController::class, 'procurementManagerDashboard']);
    Route::get('/dashboard/supply-chain-director', [DashboardController::class, 'supplyChainDirectorDashboard']);
    Route::get('/dashboard/vendor', [DashboardController::class, 'vendorDashboard']);
    Route::get('/dashboard/finance', [DashboardController::class, 'financeDashboard']);
    Route::get('/dashboard/recent-activities', [DashboardController::class, 'getRecentActivities']);

    // Notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/statistics', [NotificationController::class, 'statistics']);
    Route::get('/notifications/{id}', [NotificationController::class, 'show']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
    Route::post('/notifications/announcement', [NotificationController::class, 'sendAnnouncement']);
});

// Public vendor registration
Route::get('/vendors/categories', [VendorController::class, 'categories']);
Route::post('/vendors/register', [VendorController::class, 'register']);

// ===============================
// Logistics Module v1 (versioned)
// ===============================
Route::prefix('v1/logistics')->group(function () {
    $logisticsInternalRoles = 'role:procurement_manager,logistics_manager,supply_chain_director,admin,executive,chairman,finance';
    // Public auth endpoints
    Route::post('/auth/login', [LogisticsAuthController::class, 'login']);
    Route::post('/auth/vendor-accept', [LogisticsAuthController::class, 'vendorAccept']);
    Route::get('/docs', [LogisticsDocsController::class, 'ui']);
    Route::get('/openapi.yaml', [LogisticsDocsController::class, 'spec']);

    Route::middleware('auth:sanctum')->group(function () use ($logisticsInternalRoles) {
        Route::get('/auth/me', [LogisticsAuthController::class, 'me']);
        Route::post('/auth/vendor-invite', [LogisticsAuthController::class, 'vendorInvite'])->middleware($logisticsInternalRoles);

        // Vendor Management
        Route::post('/vendors', [LogisticsVendorController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/vendors', [LogisticsVendorController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/vendors/{id}', [LogisticsVendorController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/vendors/{id}', [LogisticsVendorController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/vendors/{id}/invite', [LogisticsVendorController::class, 'invite'])->middleware($logisticsInternalRoles);

        // Trip Management
        Route::post('/trips', [LogisticsTripController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/trips', [LogisticsTripController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{id}', [LogisticsTripController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/cancel', [LogisticsTripController::class, 'cancel'])->middleware($logisticsInternalRoles);
        Route::post('/trips/bulk-upload', [LogisticsTripController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);

        // Journey Management
        Route::post('/journeys', [LogisticsJourneyController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/journeys/{trip_id}', [LogisticsJourneyController::class, 'listByTrip'])->middleware($logisticsInternalRoles);
        Route::put('/journeys/{id}', [LogisticsJourneyController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/journeys/{id}/update-status', [LogisticsJourneyController::class, 'updateStatus'])->middleware('role:vendor,logistics_manager,procurement_manager,supply_chain_director,admin');

        // Materials Management
        Route::post('/materials', [LogisticsMaterialController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/materials', [LogisticsMaterialController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/materials/{id}', [LogisticsMaterialController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::delete('/materials/{id}', [LogisticsMaterialController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/materials/bulk-upload', [LogisticsMaterialController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{id}/materials', [LogisticsMaterialController::class, 'listByTrip'])->middleware($logisticsInternalRoles);

        // Fleet Management
        Route::post('/fleet/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/alerts', [LogisticsFleetController::class, 'getAlerts'])->middleware('auth:sanctum');

        // Compatibility aliases for older clients (vehicles without /fleet prefix)
        Route::post('/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);

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
    $logisticsInternalRoles = 'role:procurement_manager,logistics_manager,supply_chain_director,admin,executive,chairman,finance';

    Route::post('/auth/login', [LogisticsAuthController::class, 'login']);
    Route::post('/auth/vendor-accept', [LogisticsAuthController::class, 'vendorAccept']);
    Route::get('/docs', [LogisticsDocsController::class, 'ui']);
    Route::get('/openapi.yaml', [LogisticsDocsController::class, 'spec']);

    Route::middleware('auth:sanctum')->group(function () use ($logisticsInternalRoles) {
        Route::get('/auth/me', [LogisticsAuthController::class, 'me']);
        Route::post('/auth/vendor-invite', [LogisticsAuthController::class, 'vendorInvite'])->middleware($logisticsInternalRoles);

        Route::post('/vendors', [LogisticsVendorController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/vendors', [LogisticsVendorController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/vendors/{id}', [LogisticsVendorController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/vendors/{id}', [LogisticsVendorController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/vendors/{id}/invite', [LogisticsVendorController::class, 'invite'])->middleware($logisticsInternalRoles);

        Route::post('/trips', [LogisticsTripController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/trips', [LogisticsTripController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{id}', [LogisticsTripController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::patch('/trips/{id}', [LogisticsTripController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
        Route::put('/trips/{id}/assign-vendor', [LogisticsTripController::class, 'assignVendor'])->middleware($logisticsInternalRoles);
        Route::post('/trips/{id}/cancel', [LogisticsTripController::class, 'cancel'])->middleware($logisticsInternalRoles);
        Route::post('/trips/bulk-upload', [LogisticsTripController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);

        Route::post('/journeys', [LogisticsJourneyController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/journeys/{trip_id}', [LogisticsJourneyController::class, 'listByTrip'])->middleware($logisticsInternalRoles);
        Route::put('/journeys/{id}', [LogisticsJourneyController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::post('/journeys/{id}/update-status', [LogisticsJourneyController::class, 'updateStatus'])->middleware('role:vendor,logistics_manager,procurement_manager,supply_chain_director,admin');

        Route::post('/materials', [LogisticsMaterialController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/materials', [LogisticsMaterialController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/materials/{id}', [LogisticsMaterialController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::delete('/materials/{id}', [LogisticsMaterialController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/materials/bulk-upload', [LogisticsMaterialController::class, 'bulkUpload'])->middleware($logisticsInternalRoles);
        Route::get('/trips/{id}/materials', [LogisticsMaterialController::class, 'listByTrip'])->middleware($logisticsInternalRoles);

        Route::post('/fleet/vehicles', [LogisticsFleetController::class, 'store'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles', [LogisticsFleetController::class, 'index'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'show'])->middleware($logisticsInternalRoles);
        Route::put('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'update'])->middleware($logisticsInternalRoles);
        Route::delete('/fleet/vehicles/{id}', [LogisticsFleetController::class, 'destroy'])->middleware($logisticsInternalRoles);
        Route::post('/fleet/vehicles/{id}/maintenance', [LogisticsFleetController::class, 'storeMaintenance'])->middleware($logisticsInternalRoles);
        Route::get('/fleet/alerts', [LogisticsFleetController::class, 'getAlerts'])->middleware('auth:sanctum');

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
