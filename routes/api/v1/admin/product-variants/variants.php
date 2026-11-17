<?php

use App\Http\Controllers\Api\V1\Admin\Store\ActivateVariantController;
use App\Http\Controllers\Api\V1\Admin\Store\AdjustStockController;
use App\Http\Controllers\Api\V1\Admin\Store\StopVariantController;
use App\Http\Controllers\Api\V1\Admin\Store\UpdateVariantController;
use Illuminate\Support\Facades\Route;

// Store product variants (Slice 18, S18 — slice-18-contract.md §5.3). Everything needs products.manage (D271).
Route::middleware('permission:products.manage')->group(function () {
    Route::put('/{variant}', UpdateVariantController::class)->whereNumber('variant');
    Route::post('/{variant}/stop', StopVariantController::class)->whereNumber('variant');
    Route::post('/{variant}/activate', ActivateVariantController::class)->whereNumber('variant');
    Route::post('/{variant}/stock', AdjustStockController::class)->whereNumber('variant');
});
