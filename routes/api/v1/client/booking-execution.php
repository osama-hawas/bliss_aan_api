<?php

use App\Http\Controllers\Api\V1\Client\Bookings\ListCancellationReasonsController;
use App\Http\Controllers\Api\V1\Client\Bookings\RequestCancellationController;
use App\Http\Controllers\Api\V1\Client\Bookings\SubmitReviewController;
use App\Http\Controllers\Api\V1\Client\Bookings\WithdrawCancellationController;
use Illuminate\Support\Facades\Route;

// Slice 6 (docs/slice-6-contract.md): cancellation requests (D127) and reviews (D129).
Route::post('/bookings/{booking}/cancellation-requests', RequestCancellationController::class)->whereNumber('booking');
Route::post('/cancellation-requests/{cancellation}/withdraw', WithdrawCancellationController::class)->whereNumber('cancellation');
Route::get('/cancellation-reasons', ListCancellationReasonsController::class);
Route::post('/bookings/{booking}/review', SubmitReviewController::class)->whereNumber('booking');
