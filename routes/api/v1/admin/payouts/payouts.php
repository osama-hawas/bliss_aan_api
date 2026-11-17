<?php

use App\Http\Controllers\Api\V1\Admin\Payouts\ListPayoutsController;
use App\Http\Controllers\Api\V1\Admin\Payouts\MarkPayoutPaidController;
use App\Http\Controllers\Api\V1\Admin\Payouts\RejectPayoutController;
use App\Http\Controllers\Api\V1\Admin\Payouts\ShowPayoutController;
use App\Http\Controllers\Api\V1\Admin\Payouts\ShowPayoutIbanController;
use Illuminate\Support\Facades\Route;

// Slice 11 (slice-11-contract.md §6): reading needs finance.view or payouts.process; acting needs payouts.process.
Route::middleware('permission:finance.view|payouts.process')->group(function () {
    Route::get('/', ListPayoutsController::class);
    Route::get('/{payout}', ShowPayoutController::class)->whereNumber('payout');
});
Route::middleware('permission:payouts.process')->group(function () {
    Route::get('/{payout}/iban', ShowPayoutIbanController::class)->whereNumber('payout');
    Route::post('/{payout}/mark-paid', MarkPayoutPaidController::class)->whereNumber('payout');
    Route::post('/{payout}/reject', RejectPayoutController::class)->whereNumber('payout');
});
