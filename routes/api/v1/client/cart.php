<?php

use App\Http\Controllers\Api\V1\Client\Cart\ClearCartController;
use App\Http\Controllers\Api\V1\Client\Cart\RemoveCartItemController;
use App\Http\Controllers\Api\V1\Client\Cart\SetCartItemController;
use App\Http\Controllers\Api\V1\Client\Cart\ShowCartController;
use Illuminate\Support\Facades\Route;

// Slice 9: her server-side cart. No checkout, orders, promo codes or payment here (slice-9-contract.md §5).
Route::get('/cart', ShowCartController::class);
Route::delete('/cart', ClearCartController::class);
Route::put('/cart/items/{variant}', SetCartItemController::class)->whereNumber('variant');
Route::delete('/cart/items/{variant}', RemoveCartItemController::class)->whereNumber('variant');
