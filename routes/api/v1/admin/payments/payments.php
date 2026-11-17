<?php

use App\Http\Controllers\Api\V1\Admin\Payments\ListPaymentsController;
use App\Http\Controllers\Api\V1\Admin\Payments\ShowPaymentController;
use App\Http\Controllers\Api\V1\Admin\Payments\StoreRefundController;
use Illuminate\Support\Facades\Route;

// Slice 10: payments (finance.view) and staff refunds (refunds.process) — D187, D192.
Route::middleware('permission:finance.view')->group(function () {
    Route::get('/', ListPaymentsController::class);
    Route::get('/{payment}', ShowPaymentController::class)->whereNumber('payment');
});
Route::post('/{payment}/refunds', StoreRefundController::class)->whereNumber('payment')->middleware('permission:refunds.process');
