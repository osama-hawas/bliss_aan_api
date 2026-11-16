<?php

use App\Http\Controllers\Api\V1\Provider\Bookings\CompleteBookingController;
use App\Http\Controllers\Api\V1\Provider\Bookings\ListCancellationReasonsController;
use App\Http\Controllers\Api\V1\Provider\Bookings\RequestCancellationController;
use App\Http\Controllers\Api\V1\Provider\Bookings\StartBookingController;
use App\Http\Controllers\Api\V1\Provider\Bookings\WithdrawCancellationController;
use App\Http\Controllers\Api\V1\Provider\Reviews\ListReportReasonsController;
use App\Http\Controllers\Api\V1\Provider\Reviews\ListReviewsController;
use App\Http\Controllers\Api\V1\Provider\Reviews\ReplyToReviewController;
use App\Http\Controllers\Api\V1\Provider\Reviews\ReportReviewController;
use Illuminate\Support\Facades\Route;

// Slice 6 (docs/slice-6-contract.md): start / complete (D125, D126), inability (D127, D128), her reviews (D129).
Route::post('/bookings/{booking}/start', StartBookingController::class)->whereNumber('booking');
Route::post('/bookings/{booking}/complete', CompleteBookingController::class)->whereNumber('booking');
Route::post('/bookings/{booking}/cancellation-requests', RequestCancellationController::class)->whereNumber('booking');
Route::post('/cancellation-requests/{cancellation}/withdraw', WithdrawCancellationController::class)->whereNumber('cancellation');
Route::get('/cancellation-reasons', ListCancellationReasonsController::class);
Route::get('/reviews', ListReviewsController::class);
Route::post('/reviews/{review}/reply', ReplyToReviewController::class)->whereNumber('review');
Route::post('/reviews/{review}/report', ReportReviewController::class)->whereNumber('review');
Route::get('/review-report-reasons', ListReportReasonsController::class);
