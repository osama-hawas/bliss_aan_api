<?php

use App\Http\Controllers\Api\V1\Shared\Store\ListProductCategoriesController;
use App\Http\Controllers\Api\V1\Shared\Store\ListProductsController;
use App\Http\Controllers\Api\V1\Shared\Store\ShowProductController;
use Illuminate\Support\Facades\Route;

// Slice 9 store catalog, read-only. Guests can browse; the cart needs a client token (client/cart).
Route::middleware('auth.optional')->prefix('store')->group(function () {
    Route::get('/categories', ListProductCategoriesController::class);
    Route::get('/products', ListProductsController::class);
    Route::get('/products/{product}', ShowProductController::class)->whereNumber('product');
});
