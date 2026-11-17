<?php

use App\Http\Controllers\Api\V1\Admin\Store\ActivateProductCategoryController;
use App\Http\Controllers\Api\V1\Admin\Store\CreateProductCategoryController;
use App\Http\Controllers\Api\V1\Admin\Store\ListProductCategoriesController;
use App\Http\Controllers\Api\V1\Admin\Store\MoveProductCategoryController;
use App\Http\Controllers\Api\V1\Admin\Store\ShowProductCategoryController;
use App\Http\Controllers\Api\V1\Admin\Store\StopProductCategoryController;
use App\Http\Controllers\Api\V1\Admin\Store\UpdateProductCategoryController;
use Illuminate\Support\Facades\Route;

// Store categories (Slice 18, S18 — slice-18-contract.md §5.1). Everything needs products.manage (D271).
Route::middleware('permission:products.manage')->group(function () {
    Route::get('/', ListProductCategoriesController::class);
    Route::post('/', CreateProductCategoryController::class);
    Route::get('/{category}', ShowProductCategoryController::class)->whereNumber('category');
    Route::put('/{category}', UpdateProductCategoryController::class)->whereNumber('category');
    Route::post('/{category}/stop', StopProductCategoryController::class)->whereNumber('category');
    Route::post('/{category}/activate', ActivateProductCategoryController::class)->whereNumber('category');
    Route::post('/{category}/move', MoveProductCategoryController::class)->whereNumber('category');
});
