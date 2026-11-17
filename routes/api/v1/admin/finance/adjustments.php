<?php

use App\Http\Controllers\Api\V1\Admin\Finance\AdjustmentLimitsController;
use App\Http\Controllers\Api\V1\Admin\Finance\ApproveLedgerAdjustmentController;
use App\Http\Controllers\Api\V1\Admin\Finance\CreateLedgerAdjustmentController;
use App\Http\Controllers\Api\V1\Admin\Finance\ListLedgerAdjustmentsController;
use App\Http\Controllers\Api\V1\Admin\Finance\RejectLedgerAdjustmentController;
use App\Http\Controllers\Api\V1\Admin\Finance\ShowLedgerAdjustmentController;
use Illuminate\Support\Facades\Route;

// Slice 19 (S19) — provider ledger adjustments (D286–D293). Reading: finance.view or finance.process; writing: finance.process.
Route::middleware('permission:finance.view|finance.process')->group(function () {
    Route::get('/adjustment-limits', AdjustmentLimitsController::class);
    Route::get('/adjustments', ListLedgerAdjustmentsController::class);
    Route::get('/adjustments/{adjustment}', ShowLedgerAdjustmentController::class)->whereNumber('adjustment');
});

Route::middleware('permission:finance.process')->group(function () {
    Route::post('/adjustments', CreateLedgerAdjustmentController::class);
    Route::post('/adjustments/{adjustment}/approve', ApproveLedgerAdjustmentController::class)->whereNumber('adjustment');
    Route::post('/adjustments/{adjustment}/reject', RejectLedgerAdjustmentController::class)->whereNumber('adjustment');
});
