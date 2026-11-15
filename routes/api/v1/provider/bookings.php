<?php

use App\Http\Controllers\Api\V1\Provider\Bookings\ListBookingsController;
use App\Http\Controllers\Api\V1\Provider\Bookings\ShowBookingController;
use Illuminate\Support\Facades\Route;

// Her confirmed bookings. Slice 5c.
Route::get('/bookings', ListBookingsController::class);
Route::get('/bookings/{booking}', ShowBookingController::class)->whereNumber('booking');
