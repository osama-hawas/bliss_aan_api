<?php

use App\Http\Controllers\Api\V1\Admin\Finance\FinanceOverviewController;
use App\Http\Controllers\Api\V1\Admin\Finance\ListLedgerEntriesController;
use App\Http\Controllers\Api\V1\Admin\Finance\ListProviderBalancesController;
use App\Http\Controllers\Api\V1\Admin\Finance\ReconciliationCheckRowsController;
use App\Http\Controllers\Api\V1\Admin\Finance\ReconciliationChecksController;
use App\Http\Controllers\Api\V1\Admin\Finance\ReconciliationDailyController;
use App\Http\Controllers\Api\V1\Admin\Finance\ShowProviderBalanceController;
use Illuminate\Support\Facades\Route;

// Slice 19 (S19) — read-only finance views (D294–D296), finance.view. No exports here (Slice 21, D297).
Route::middleware('permission:finance.view')->group(function () {
    Route::get('/overview', FinanceOverviewController::class);
    Route::get('/provider-balances', ListProviderBalancesController::class);
    Route::get('/provider-balances/{provider}', ShowProviderBalanceController::class)->whereNumber('provider');
    Route::get('/ledger-entries', ListLedgerEntriesController::class);
    Route::get('/reconciliation/checks', ReconciliationChecksController::class);
    Route::get('/reconciliation/checks/{check}', ReconciliationCheckRowsController::class)->where('check', '[a-z_]+');
    Route::get('/reconciliation/daily', ReconciliationDailyController::class);
});
