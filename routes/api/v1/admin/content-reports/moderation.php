<?php

use App\Http\Controllers\Api\V1\Admin\Reviews\DecideContentReportController;
use App\Http\Controllers\Api\V1\Admin\Reviews\ListContentReportsController;
use Illuminate\Support\Facades\Route;

// Slice 17 (slice-17-contract.md §8, D262): content report decisions.
Route::middleware('permission:reviews.moderate')->group(function () {
    Route::get('/', ListContentReportsController::class);
    Route::post('/{report}/decide', DecideContentReportController::class)->whereNumber('report');
});
