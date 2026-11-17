<?php

use App\Http\Controllers\Api\V1\Admin\Catalog as C;
use Illuminate\Support\Facades\Route;

// Catalog management (Req 10, 12, 55; Slice 16, docs/slice-16-contract.md). Everything is `catalog.manage` (D182).
// Rows are stopped and activated, never deleted: there is no DELETE route (BRD, D180).
Route::middleware('permission:catalog.manage')->group(function () {
    Route::prefix('categories')->group(function () {
        Route::get('/', C\ListCategoriesController::class);
        Route::post('/', C\StoreCategoryController::class);
        Route::get('/{category}', C\ShowCategoryController::class)->whereNumber('category');
        Route::put('/{category}', C\UpdateCategoryController::class)->whereNumber('category');
        Route::post('/{category}/impact', C\CategoryImpactController::class)->whereNumber('category');
        Route::post('/{category}/stop', C\StopCategoryController::class)->whereNumber('category');
        Route::post('/{category}/activate', C\ActivateCategoryController::class)->whereNumber('category');
        Route::post('/{category}/move', C\MoveCategoryController::class)->whereNumber('category');
    });

    Route::prefix('services')->group(function () {
        Route::get('/', C\ListCatalogServicesController::class);
        Route::post('/', C\StoreCatalogServiceController::class);
        Route::get('/{service}', C\ShowCatalogServiceController::class)->whereNumber('service');
        Route::put('/{service}', C\UpdateCatalogServiceController::class)->whereNumber('service');
        Route::post('/{service}/impact', C\CatalogServiceImpactController::class)->whereNumber('service');
        Route::post('/{service}/stop', C\StopCatalogServiceController::class)->whereNumber('service');
        Route::post('/{service}/activate', C\ActivateCatalogServiceController::class)->whereNumber('service');
        Route::post('/{service}/move', C\MoveCatalogServiceController::class)->whereNumber('service');
    });

    Route::prefix('addons')->group(function () {
        Route::get('/', C\ListAddonsController::class);
        Route::post('/', C\StoreAddonController::class);
        Route::get('/{addon}', C\ShowAddonController::class)->whereNumber('addon');
        Route::put('/{addon}', C\UpdateAddonController::class)->whereNumber('addon');
        Route::post('/{addon}/stop', C\StopAddonController::class)->whereNumber('addon');
        Route::post('/{addon}/activate', C\ActivateAddonController::class)->whereNumber('addon');
        Route::post('/{addon}/move', C\MoveAddonController::class)->whereNumber('addon');
    });

    // D183a.
    Route::prefix('event-types')->group(function () {
        Route::get('/', C\ListEventTypesController::class);
        Route::post('/', C\StoreEventTypeController::class);
        Route::get('/{eventType}', C\ShowEventTypeController::class)->whereNumber('eventType');
        Route::put('/{eventType}', C\UpdateEventTypeController::class)->whereNumber('eventType');
        Route::post('/{eventType}/stop', C\StopEventTypeController::class)->whereNumber('eventType');
        Route::post('/{eventType}/activate', C\ActivateEventTypeController::class)->whereNumber('eventType');
        Route::post('/{eventType}/move', C\MoveEventTypeController::class)->whereNumber('eventType');
    });
});
