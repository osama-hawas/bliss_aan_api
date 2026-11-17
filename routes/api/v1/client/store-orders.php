<?php

use App\Http\Controllers\Api\V1\Client\Store\ListStoreOrdersController;
use App\Http\Controllers\Api\V1\Client\Store\PayStoreOrderController;
use App\Http\Controllers\Api\V1\Client\Store\PlaceStoreOrderController;
use App\Http\Controllers\Api\V1\Client\Store\PreviewCheckoutController;
use App\Http\Controllers\Api\V1\Client\Store\ReorderStoreOrderController;
use App\Http\Controllers\Api\V1\Client\Store\ShowStoreOrderController;
use Illuminate\Support\Facades\Route;

// Slice 9b: checkout, payment (through Slice 10), her orders, reorder (slice-9b-contract.md §6).
Route::post('/store-orders/preview', PreviewCheckoutController::class);
Route::post('/store-orders', PlaceStoreOrderController::class);
Route::get('/store-orders', ListStoreOrdersController::class);
Route::get('/store-orders/{order}', ShowStoreOrderController::class)->whereNumber('order');
Route::post('/store-orders/{order}/payments', PayStoreOrderController::class)->whereNumber('order');
Route::post('/store-orders/{order}/reorder', ReorderStoreOrderController::class)->whereNumber('order');
