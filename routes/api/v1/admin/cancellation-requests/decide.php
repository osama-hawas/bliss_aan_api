<?php

use App\Http\Controllers\Api\V1\Admin\Cancellations\DecideCancellationRequestController;
use App\Http\Controllers\Api\V1\Admin\Cancellations\ListCancellationRequestsController;
use App\Http\Controllers\Api\V1\Admin\Cancellations\ShowCancellationRequestController;
use Illuminate\Support\Facades\Route;

// Booking cancellation requests (Slice 6, D131). Reading needs bookings.view; deciding also cancellations.decide.
Route::middleware('permission:bookings.view')->group(function () {
    Route::get('/', ListCancellationRequestsController::class);
    Route::get('/{cancellation}', ShowCancellationRequestController::class)->whereNumber('cancellation');
    Route::post('/{cancellation}/decide', DecideCancellationRequestController::class)
        ->whereNumber('cancellation')->middleware('permission:cancellations.decide');
});
