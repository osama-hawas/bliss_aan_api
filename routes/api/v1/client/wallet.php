<?php

use App\Http\Controllers\Api\V1\Client\Wallet\DeleteWalletController;
use App\Http\Controllers\Api\V1\Client\Wallet\ListWalletTransactionsController;
use App\Http\Controllers\Api\V1\Client\Wallet\ShowWalletController;
use App\Http\Controllers\Api\V1\Client\Wallet\StoreWalletController;
use App\Http\Controllers\Api\V1\Client\Wallet\TopUpWalletController;
use App\Http\Controllers\Api\V1\Client\Wallet\UpdateWalletController;
use Illuminate\Support\Facades\Route;

// Her own wallet. Clients: money in (top-ups, refunds) and out (booking payments) — owner decision 2026-11-19.
Route::get('/wallet', ShowWalletController::class);
Route::post('/wallet', StoreWalletController::class);
Route::patch('/wallet', UpdateWalletController::class);
Route::delete('/wallet', DeleteWalletController::class);
// Owner decision 2026-11-19: add money, and her transaction history.
Route::post('/wallet/top-ups', TopUpWalletController::class);
Route::get('/wallet/transactions', ListWalletTransactionsController::class);
