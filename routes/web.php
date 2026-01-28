<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/storage-test', function () {
    dd(
        config('filesystems.default'),
        config('filesystems.documents_disk')
    );
});
