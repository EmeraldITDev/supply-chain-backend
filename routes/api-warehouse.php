<?php

use App\Http\Controllers\Api\Warehouse\WarehouseInventoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('warehouse')->group(function () {
    Route::get('/dashboard', [WarehouseInventoryController::class, 'dashboard'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,executive');
    Route::get('/reports/{report}', [WarehouseInventoryController::class, 'reports'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,finance,finance_officer,executive');
    Route::get('/export', [WarehouseInventoryController::class, 'exportReport'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,finance,finance_officer,executive');

    Route::get('/locations', [WarehouseInventoryController::class, 'listLocations'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,executive');
    Route::post('/locations', [WarehouseInventoryController::class, 'storeLocation'])
        ->middleware('role:admin,warehouse_manager');
    Route::put('/locations/{id}', [WarehouseInventoryController::class, 'updateLocation'])
        ->middleware('role:admin,warehouse_manager');
    Route::delete('/locations/{id}', [WarehouseInventoryController::class, 'destroyLocation'])
        ->middleware('role:admin,warehouse_manager');

    Route::get('/items', [WarehouseInventoryController::class, 'listItems'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,executive');
    Route::post('/items', [WarehouseInventoryController::class, 'storeItem'])
        ->middleware('role:admin,warehouse_manager');
    Route::put('/items/{id}', [WarehouseInventoryController::class, 'updateItem'])
        ->middleware('role:admin,warehouse_manager');
    Route::delete('/items/{id}', [WarehouseInventoryController::class, 'destroyItem'])
        ->middleware('role:admin,warehouse_manager');
    Route::get('/items/lookup', [WarehouseInventoryController::class, 'lookupItems'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,executive');
    Route::post('/items/{id}/attachments', [WarehouseInventoryController::class, 'uploadItemAttachments'])
        ->middleware('role:admin,warehouse_manager');

    Route::get('/inventory', [WarehouseInventoryController::class, 'inventory'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,finance,finance_officer,executive');
    Route::get('/inventory/low-stock', [WarehouseInventoryController::class, 'lowStockInventory'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,executive');
    Route::post('/inventory/{id}/quarantine', [WarehouseInventoryController::class, 'quarantineInventory'])
        ->middleware('role:admin,warehouse_manager');

    Route::get('/movements', [WarehouseInventoryController::class, 'movements'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,finance,finance_officer,executive');
    Route::post('/movements/transfer', [WarehouseInventoryController::class, 'transfer'])
        ->middleware('role:admin,warehouse_manager');
    Route::post('/movements/adjustment', [WarehouseInventoryController::class, 'adjustment'])
        ->middleware('role:admin,warehouse_manager');
    Route::post('/movements/{id}/approve', [WarehouseInventoryController::class, 'approveMovement'])
        ->middleware('role:admin,warehouse_manager');
    Route::post('/movements/vendor-return', [WarehouseInventoryController::class, 'vendorReturn'])
        ->middleware('role:admin,warehouse_manager,procurement_manager');

    Route::post('/goods-receipts', [WarehouseInventoryController::class, 'goodsReceipts'])
        ->middleware('role:admin,warehouse_manager,procurement_manager');

    Route::get('/stock-counts', [WarehouseInventoryController::class, 'stockCounts'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,executive');
    Route::post('/stock-counts', [WarehouseInventoryController::class, 'stockCounts'])
        ->middleware('role:admin,warehouse_manager');
    Route::get('/stock-counts/{id}', [WarehouseInventoryController::class, 'stockCountShow'])
        ->middleware('role:admin,warehouse_manager,procurement_manager,executive');
    Route::post('/stock-counts/{id}/lines', [WarehouseInventoryController::class, 'stockCountLines'])
        ->middleware('role:admin,warehouse_manager');
    Route::post('/stock-counts/{id}/approve', [WarehouseInventoryController::class, 'approveStockCount'])
        ->middleware('role:admin,warehouse_manager');
    Route::post('/stock-counts/{id}/post', [WarehouseInventoryController::class, 'postStockCount'])
        ->middleware('role:admin,warehouse_manager');
});
