<?php

use App\Http\Controllers\Api\V1\Provider\Payouts\ListLedgerEntriesController;
use App\Http\Controllers\Api\V1\Provider\Payouts\ListPayoutRequestsController;
use App\Http\Controllers\Api\V1\Provider\Payouts\SavePayoutAccountController;
use App\Http\Controllers\Api\V1\Provider\Payouts\ShowEarningsController;
use App\Http\Controllers\Api\V1\Provider\Payouts\ShowPayoutAccountController;
use App\Http\Controllers\Api\V1\Provider\Payouts\ShowPayoutRequestController;
use App\Http\Controllers\Api\V1\Provider\Payouts\StorePayoutRequestController;
use Illuminate\Support\Facades\Route;

// Slice 11 (slice-11-contract.md §5): her earnings, payout account and payout requests. Own data only, no other ids.
Route::get('/earnings', ShowEarningsController::class);
Route::get('/earnings/entries', ListLedgerEntriesController::class);
Route::get('/payout-account', ShowPayoutAccountController::class);
Route::put('/payout-account', SavePayoutAccountController::class);
Route::get('/payout-requests', ListPayoutRequestsController::class);
Route::post('/payout-requests', StorePayoutRequestController::class);
Route::get('/payout-requests/{payoutRequest}', ShowPayoutRequestController::class)->whereNumber('payoutRequest');
