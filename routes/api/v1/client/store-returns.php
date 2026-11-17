<?php

use App\Http\Controllers\Api\V1\Client\Store\CancelStoreReturnController;
use App\Http\Controllers\Api\V1\Client\Store\CreateStoreReturnController;
use App\Http\Controllers\Api\V1\Client\Store\ListStoreReturnsController;
use App\Http\Controllers\Api\V1\Client\Store\ShowStoreReturnController;
use Illuminate\Support\Facades\Route;

// S23: store returns (OD37, slice-23-contract.md §5). One order's returns, and all of hers for the status screen.
Route::get('/store-orders/{order}/returns', ListStoreReturnsController::class)->whereNumber('order');
Route::post('/store-orders/{order}/returns', CreateStoreReturnController::class)->whereNumber('order');
Route::get('/store-orders/{order}/returns/{return}', ShowStoreReturnController::class)->whereNumber(['order', 'return']);
Route::post('/store-orders/{order}/returns/{return}/cancel', CancelStoreReturnController::class)->whereNumber(['order', 'return']);
Route::get('/store-returns', ListStoreReturnsController::class);
Route::get('/store-returns/{return}', ShowStoreReturnController::class)->whereNumber('return');
Route::post('/store-returns/{return}/cancel', CancelStoreReturnController::class)->whereNumber('return');
