<?php

use App\Http\Controllers\Api\V1\Admin\Clients\ListDeletionRequestsController;
use App\Http\Controllers\Api\V1\Admin\Clients\ProcessDeletionRequestController;
use App\Http\Controllers\Api\V1\Admin\Clients\ShowDeletionRequestController;
use Illuminate\Support\Facades\Route;

// S22 (OD38, D336): account deletion requests of clients and providers. `clients.edit` for both.
Route::get('/deletion-requests', ListDeletionRequestsController::class)->middleware('permission:clients.edit');
Route::get('/deletion-requests/{deletionRequest}', ShowDeletionRequestController::class)->whereNumber('deletionRequest')->middleware('permission:clients.edit');
Route::post('/deletion-requests/{deletionRequest}/process', ProcessDeletionRequestController::class)->whereNumber('deletionRequest')->middleware('permission:clients.edit');
