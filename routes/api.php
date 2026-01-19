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

// API health check/test route
Route::get('/', function () {
    return response()->json([
        'message' => 'Supply Chain API is running',
        'version' => '1.0',
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
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/vendor/change-password', [AuthController::class, 'forcePasswordChange']);

// Vendor authentication (public)
Route::post('/vendors/auth/login', [VendorAuthController::class, 'login']);
Route::post('/vendors/auth/password-reset', [VendorAuthController::class, 'requestPasswordReset']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Authentication
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/refresh', [AuthController::class, 'refreshToken']); // Alias for frontend compatibility
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

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
    Route::get('/mrfs/{id}/available-actions', [MRFController::class, 'getAvailableActions']);
    Route::post('/mrfs', [MRFController::class, 'store']);
    Route::put('/mrfs/{id}', [MRFController::class, 'update']);
    Route::post('/mrfs/{id}/approve', [MRFController::class, 'approve']); // Legacy
    Route::post('/mrfs/{id}/reject', [MRFController::class, 'reject']); // Legacy
    Route::delete('/mrfs/{id}', [MRFController::class, 'destroy']);
    
    // MRF Workflow routes (new multi-stage approval)
    Route::post('/mrfs/{id}/procurement-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'procurementApprove']);
    Route::post('/mrfs/{id}/executive-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'executiveApprove']);
    Route::post('/mrfs/{id}/chairman-approve', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'chairmanApprove']);
    
    // Vendor selection workflow routes
    Route::post('/mrfs/{id}/send-vendor-for-approval', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'sendVendorForApproval']);
    Route::post('/mrfs/{id}/approve-vendor-selection', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'approveVendorSelection']);
    Route::post('/mrfs/{id}/reject-vendor-selection', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'rejectVendorSelection']);
    Route::post('/mrfs/{id}/generate-po', [\App\Http\Controllers\Api\MRFWorkflowController::class, 'generatePO']);
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

    // Vendor routes - specific routes must come before parameterized routes
    Route::get('/vendors', [VendorController::class, 'index']);
    Route::get('/vendors/quotations', [VendorController::class, 'getVendorQuotations']);
    Route::get('/vendors/{id}', [VendorController::class, 'show']);
    Route::delete('/vendors/{id}', [VendorController::class, 'destroy']);
    Route::post('/vendors/invite', [VendorController::class, 'inviteVendor']);
    Route::post('/vendors/{id}/rating', [VendorController::class, 'addRating']);
    Route::get('/vendors/{id}/comments', [VendorController::class, 'getComments']);
    Route::get('/vendors/registrations', [VendorController::class, 'registrations']);
    Route::get('/vendors/registrations/{id}', [VendorController::class, 'getRegistration']);
    Route::get('/vendors/registrations/{registrationId}/documents/{documentId}/download', [VendorController::class, 'downloadDocument']);
    Route::post('/vendors/registrations/{id}/approve', [VendorController::class, 'approveRegistration']);
    Route::post('/vendors/registrations/{id}/reject', [VendorController::class, 'rejectRegistration']);
    Route::put('/vendors/{id}/credentials', [VendorController::class, 'updateVendorCredentials']);

    // Dashboard routes
    Route::get('/dashboard/procurement-manager', [DashboardController::class, 'procurementManagerDashboard']);
    Route::get('/dashboard/supply-chain-director', [DashboardController::class, 'supplyChainDirectorDashboard']);
    Route::get('/dashboard/vendor', [DashboardController::class, 'vendorDashboard']);

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
Route::post('/vendors/register', [VendorController::class, 'register']);
