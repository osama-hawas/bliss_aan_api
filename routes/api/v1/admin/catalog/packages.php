<?php

use App\Http\Controllers\Api\V1\Admin\Catalog as C;
use Illuminate\Support\Facades\Route;

// Service packages (Req 16, OD39; Slice 24, docs/slice-24-contract.md §4). `catalog.manage` like the rest of the catalog
// (D182). No price (D350) and no DELETE route: packages are stopped and activated, never deleted (D180).
Route::middleware('permission:catalog.manage')->prefix('packages')->group(function () {
    Route::get('/', C\ListPackagesController::class);
    Route::post('/', C\StorePackageController::class);
    Route::get('/{package}', C\ShowPackageController::class)->whereNumber('package');
    Route::put('/{package}', C\UpdatePackageController::class)->whereNumber('package');
    Route::post('/{package}/stop', C\StopPackageController::class)->whereNumber('package');
    Route::post('/{package}/activate', C\ActivatePackageController::class)->whereNumber('package');
    Route::post('/{package}/move', C\MovePackageController::class)->whereNumber('package');
});
