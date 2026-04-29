<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MRFController;

/*
| Unsigned PO PDF — signed URL (no Sanctum). Renders PDF on-the-fly so it always matches the current template.
| API JSON should expose this as unsignedPoUrl instead of a raw S3 link.
*/
Route::get('/signed/po/{id}', [MRFController::class, 'downloadPOBySignedLink'])
    ->name('mrfs.po-signed-download')
    ->middleware('signed');
