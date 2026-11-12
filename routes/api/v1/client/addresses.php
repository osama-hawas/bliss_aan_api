<?php

use App\Http\Controllers\Api\V1\Client\Addresses\DeleteAddressController;
use App\Http\Controllers\Api\V1\Client\Addresses\ListAddressesController;
use App\Http\Controllers\Api\V1\Client\Addresses\SetDefaultAddressController;
use App\Http\Controllers\Api\V1\Client\Addresses\StoreAddressController;
use App\Http\Controllers\Api\V1\Client\Addresses\UpdateAddressController;
use Illuminate\Support\Facades\Route;

// Saved addresses (BRD Req 04). Slice 4.
Route::get('/addresses', ListAddressesController::class);
Route::post('/addresses', StoreAddressController::class);
Route::patch('/addresses/{address}', UpdateAddressController::class)->whereNumber('address');
Route::delete('/addresses/{address}', DeleteAddressController::class)->whereNumber('address');
Route::post('/addresses/{address}/default', SetDefaultAddressController::class)->whereNumber('address');
