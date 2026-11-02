<?php

use Illuminate\Support\Facades\Route;

// One store-section and one admin-section route, to prove permissions are enforced by the API.
Route::get('/store-ping', fn () => response()->json(['success' => true, 'data' => 'store']))
    ->middleware('permission:products.manage');

Route::get('/accounts-ping', fn () => response()->json(['success' => true, 'data' => 'accounts']))
    ->middleware('permission:clients.view');
