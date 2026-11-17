<?php

use App\Http\Controllers\Api\V1\Shared\Catalog\ListCategoriesController;
use App\Http\Controllers\Api\V1\Shared\Catalog\ListEventTypesController;
use App\Http\Controllers\Api\V1\Shared\Catalog\ListPackagesController;
use App\Http\Controllers\Api\V1\Shared\Catalog\ListServicesController;
use App\Http\Controllers\Api\V1\Shared\Catalog\ShowHomeController;
use App\Http\Controllers\Api\V1\Shared\Catalog\ShowPackageController;
use App\Http\Controllers\Api\V1\Shared\Providers\ListProvidersController;
use App\Http\Controllers\Api\V1\Shared\Providers\ShowProviderAvailabilityController;
use App\Http\Controllers\Api\V1\Shared\Providers\ShowProviderController;
use App\Http\Controllers\Api\V1\Shared\Providers\ShowProviderServiceController;
use Illuminate\Support\Facades\Route;

// Public discovery: guests can browse; a client token only adds `is_favorite`.
Route::middleware('auth.optional')->group(function () {
    Route::get('/home', ShowHomeController::class);
    Route::get('/categories', ListCategoriesController::class);
    Route::get('/services', ListServicesController::class);
    Route::get('/event-types', ListEventTypesController::class);
    // Slice 24 (S24): service packages (OD39).
    Route::get('/packages', ListPackagesController::class);
    Route::get('/packages/{package}', ShowPackageController::class)->whereNumber('package');
    Route::get('/providers', ListProvidersController::class);
    Route::get('/providers/{provider}', ShowProviderController::class)->whereNumber('provider');
    Route::get('/providers/{provider}/availability', ShowProviderAvailabilityController::class)->whereNumber('provider'); // Slice 5a
    Route::get('/providers/{provider}/services/{service}', ShowProviderServiceController::class)->whereNumber(['provider', 'service']);
});
