<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MRFController;
use App\Http\Controllers\Api\SRFController;
use App\Http\Controllers\Api\RFQController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\VendorController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Authentication
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

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
    Route::get('/vendors/registrations', [VendorController::class, 'registrations']);
    Route::post('/vendors/registrations/{id}/approve', [VendorController::class, 'approveRegistration']);
});

// Public vendor registration
Route::post('/vendors/register', [VendorController::class, 'register']);
