<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use App\Mail\MRFCreatedMail;

Route::get('/test-resend-email', function () {
    Mail::to('it@emeraldcfze.com')->send(new MRFCreatedMail([
        'mrf_id' => 'MRF-2026-001',
        'title' => 'Test Material Request',
        'department' => 'IT',
        'created_by' => 'System Admin',
        'status' => 'Pending',
        'url' => config('app.frontend_url') . '/procurement/mrf/MRF-2026-001',
    ]));

    return 'Test email sent';
});