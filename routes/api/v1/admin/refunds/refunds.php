<?php

use App\Http\Controllers\Api\V1\Admin\Payments\ListRefundsController;
use Illuminate\Support\Facades\Route;

// Slice 10: refunds list (finance.view or refunds.process, D192).
Route::get('/', ListRefundsController::class)->middleware('permission:finance.view|refunds.process');
