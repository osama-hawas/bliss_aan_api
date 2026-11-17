<?php

use App\Http\Controllers\Api\V1\Admin\Store\ActivateProductController;
use App\Http\Controllers\Api\V1\Admin\Store\CreateProductController;
use App\Http\Controllers\Api\V1\Admin\Store\CreateVariantController;
use App\Http\Controllers\Api\V1\Admin\Store\ListProductsController;
use App\Http\Controllers\Api\V1\Admin\Store\ListStockMovementsController;
use App\Http\Controllers\Api\V1\Admin\Store\ShowProductController;
use App\Http\Controllers\Api\V1\Admin\Store\StopProductController;
use App\Http\Controllers\Api\V1\Admin\Store\UpdateProductController;
use Illuminate\Support\Facades\Route;

// Store products (Slice 18, S18 — slice-18-contract.md §5.2). Everything needs products.manage (D271).
Route::middleware('permission:products.manage')->group(function () {
    Route::get('/', ListProductsController::class);
    Route::post('/', CreateProductController::class);
    Route::get('/{product}', ShowProductController::class)->whereNumber('product');
    Route::put('/{product}', UpdateProductController::class)->whereNumber('product');
    Route::post('/{product}/stop', StopProductController::class)->whereNumber('product');
    Route::post('/{product}/activate', ActivateProductController::class)->whereNumber('product');
    Route::post('/{product}/variants', CreateVariantController::class)->whereNumber('product');
    Route::get('/{product}/stock-movements', ListStockMovementsController::class)->whereNumber('product');
});
