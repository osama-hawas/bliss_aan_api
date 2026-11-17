<?php

use App\Http\Controllers\Api\V1\Admin\Operations\AddBookingNoteController;
use App\Http\Controllers\Api\V1\Admin\Operations\CancelBookingController;
use App\Http\Controllers\Api\V1\Admin\Operations\CompleteBookingController;
use App\Http\Controllers\Api\V1\Admin\Operations\ListBookingsController;
use App\Http\Controllers\Api\V1\Admin\Operations\ShowBookingController;
use Illuminate\Support\Facades\Route;

// Slice 17 (slice-17-contract.md §5, D256): bookings list/detail (bookings.view) and staff interventions (bookings.intervene).
Route::middleware('permission:bookings.view')->group(function () {
    Route::get('/', ListBookingsController::class);
    Route::get('/{booking}', ShowBookingController::class)->whereNumber('booking');
    Route::middleware('permission:bookings.intervene')->group(function () {
        Route::post('/{booking}/cancel', CancelBookingController::class)->whereNumber('booking');
        Route::post('/{booking}/complete', CompleteBookingController::class)->whereNumber('booking');
        Route::post('/{booking}/notes', AddBookingNoteController::class)->whereNumber('booking');
    });
});
