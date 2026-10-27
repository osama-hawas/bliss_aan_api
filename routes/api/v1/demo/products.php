<?php

use App\Http\Controllers\Api\V1\Demo\ListProductsController;
use App\Http\Controllers\Api\V1\Demo\ShowProductController;
use App\Http\Controllers\Api\V1\Demo\StoreProductController;
use App\Http\Controllers\Api\V1\Demo\UpdateProductController;
use App\Http\Controllers\Api\V1\Demo\DeleteProductController;
use Illuminate\Support\Facades\Route;

// This routes file is placed in routes/api/v1/demo/
// It will automatically get the prefix 'api/v1/demo'!

Route::get('/products', ListProductsController::class);
Route::get('/products/{id}', ShowProductController::class);
Route::post('/products', StoreProductController::class);
Route::put('/products/{id}', UpdateProductController::class);
Route::delete('/products/{id}', DeleteProductController::class);
