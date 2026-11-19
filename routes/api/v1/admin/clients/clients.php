<?php

use App\Http\Controllers\Api\V1\Admin\Clients\CreateClientController;
use App\Http\Controllers\Api\V1\Admin\Clients\ListClientsController;
use App\Http\Controllers\Api\V1\Admin\Clients\ReactivateClientController;
use App\Http\Controllers\Api\V1\Admin\Clients\ShowClientController;
use App\Http\Controllers\Api\V1\Admin\Clients\SuspendClientController;
use App\Http\Controllers\Api\V1\Admin\Clients\UpdateClientController;
use Illuminate\Support\Facades\Route;

// Client accounts (Req 53, Slice 15). Only `users.type = client`; any other id is a 404.
Route::get('/', ListClientsController::class)->middleware('permission:clients.view');
Route::post('/', CreateClientController::class)->middleware('permission:clients.edit');
Route::get('/{client}', ShowClientController::class)->whereNumber('client')->middleware('permission:clients.view');
Route::put('/{client}', UpdateClientController::class)->whereNumber('client')->middleware('permission:clients.edit');
Route::post('/{client}/suspend', SuspendClientController::class)->whereNumber('client')->middleware('permission:clients.suspend');
Route::post('/{client}/reactivate', ReactivateClientController::class)->whereNumber('client')->middleware('permission:clients.suspend');
