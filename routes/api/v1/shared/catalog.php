<?php

use App\Http\Controllers\Api\V1\Shared\Catalog\ListCategoriesController;
use App\Http\Controllers\Api\V1\Shared\Catalog\ListServicesController;
use App\Http\Controllers\Api\V1\Shared\Catalog\ShowHomeController;
use App\Http\Controllers\Api\V1\Shared\Providers\ListProvidersController;
use App\Http\Controllers\Api\V1\Shared\Providers\ShowProviderController;
use App\Http\Controllers\Api\V1\Shared\Providers\ShowProviderServiceController;
use Illuminate\Support\Facades\Route;

// Public discovery: guests can browse; a client token only adds `is_favorite`.
Route::middleware('auth.optional')->group(function () {
    Route::get('/home', ShowHomeController::class);
    Route::get('/categories', ListCategoriesController::class);
    Route::get('/services', ListServicesController::class);
    Route::get('/providers', ListProvidersController::class);
    Route::get('/providers/{provider}', ShowProviderController::class)->whereNumber('provider');
    Route::get('/providers/{provider}/services/{service}', ShowProviderServiceController::class)->whereNumber(['provider', 'service']);
});
