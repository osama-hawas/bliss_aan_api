<?php

use App\Http\Controllers\Api\V1\Client\Bookings\AcceptOfferController;
use App\Http\Controllers\Api\V1\Client\Bookings\ListBookingsController;
use App\Http\Controllers\Api\V1\Client\Bookings\RejectOfferController;
use App\Http\Controllers\Api\V1\Client\Bookings\ShowBookingController;
use Illuminate\Support\Facades\Route;

// Accept an offer → confirmed booking (Req 20, D4, D107–D113), and her bookings. Slice 5c.
Route::post('/offers/{offer}/accept', AcceptOfferController::class)->whereNumber('offer');
// QA fix 2026-11-19 (BUG-012): she may reject one offer; reason optional.
Route::post('/offers/{offer}/reject', RejectOfferController::class)->whereNumber('offer');
Route::get('/bookings', ListBookingsController::class);
Route::get('/bookings/{booking}', ShowBookingController::class)->whereNumber('booking');
