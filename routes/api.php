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
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    // Vendor authentication (protected)
    Route::post('/vendors/auth/logout', [VendorAuthController::class, 'logout']);
    Route::get('/vendors/auth/me', [VendorAuthController::class, 'me']);
    Route::get('/vendors/auth/profile', [VendorAuthController::class, 'getProfile']);
    Route::put('/vendors/auth/profile', [VendorAuthController::class, 'updateProfile']);
    Route::post('/vendors/auth/change-password', [VendorAuthController::class, 'changePassword']);

    // MRF routes
    Route::get('/mrfs', [MRFController::class, 'index']);
    Route::get('/mrfs/{id}', [MRFController::class, 'show']);
    Route::post('/mrfs', [MRFController::class, 'store']);
    Route::put('/mrfs/{id}', [MRFController::class, 'update']);
    Route::post('/mrfs/{id}/approve', [MRFController::class, 'approve']);
    Route::post('/mrfs/{id}/reject', [MRFController::class, 'reject']);
    Route::delete('/mrfs/{id}', [MRFController::class, 'destroy']);

    // SRF routes
    Route::get('/srfs', [SRFController::class, 'index']);
    Route::post('/srfs', [SRFController::class, 'store']);
    Route::put('/srfs/{id}', [SRFController::class, 'update']);

    // RFQ routes
    Route::get('/rfqs', [RFQController::class, 'index']);
    Route::post('/rfqs', [RFQController::class, 'store']);
    Route::put('/rfqs/{id}', [RFQController::class, 'update']);

    // Quotation routes
    Route::get('/quotations', [QuotationController::class, 'index']);
    Route::post('/quotations', [QuotationController::class, 'store']);
    Route::post('/quotations/{id}/approve', [QuotationController::class, 'approve']);
    Route::post('/quotations/{id}/reject', [QuotationController::class, 'reject']);

    // Vendor routes
    Route::get('/vendors', [VendorController::class, 'index']);
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
});

// Public vendor registration
Route::post('/vendors/register', [VendorController::class, 'register']);
