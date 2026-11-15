<?php

use App\Http\Controllers\Api\V1\Provider\Wallet\DeleteWalletController;
use App\Http\Controllers\Api\V1\Provider\Wallet\ShowWalletController;
use App\Http\Controllers\Api\V1\Provider\Wallet\StoreWalletController;
use App\Http\Controllers\Api\V1\Provider\Wallet\UpdateWalletController;
use Illuminate\Support\Facades\Route;

// Her own informational wallet, CRUD only (D106). No money operations exist.
Route::get('/wallet', ShowWalletController::class);
Route::post('/wallet', StoreWalletController::class);
Route::patch('/wallet', UpdateWalletController::class);
Route::delete('/wallet', DeleteWalletController::class);
