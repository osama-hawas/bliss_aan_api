<?php

use App\Http\Controllers\Api\V1\Admin\Store\ApproveStoreReturnController;
use App\Http\Controllers\Api\V1\Admin\Store\ListStoreReturnsController;
use App\Http\Controllers\Api\V1\Admin\Store\ReceiveStoreReturnController;
use App\Http\Controllers\Api\V1\Admin\Store\RejectStoreReturnController;
use App\Http\Controllers\Api\V1\Admin\Store\RetryStoreReturnRefundController;
use App\Http\Controllers\Api\V1\Admin\Store\ShowStoreReturnController;
use Illuminate\Support\Facades\Route;

// S23 store returns (OD37): reading needs orders.view; actions orders.update_status; the steps that refund also
// refunds.process (two middleware = both permissions).
Route::middleware('permission:orders.view')->group(function () {
    Route::get('/', ListStoreReturnsController::class);
    Route::get('/{return}', ShowStoreReturnController::class)->whereNumber('return');

    Route::middleware('permission:orders.update_status')->group(function () {
        Route::post('/{return}/approve', ApproveStoreReturnController::class)->whereNumber('return');
        Route::post('/{return}/reject', RejectStoreReturnController::class)->whereNumber('return');

        Route::middleware('permission:refunds.process')->group(function () {
            Route::post('/{return}/receive', ReceiveStoreReturnController::class)->whereNumber('return');
            Route::post('/{return}/refund', RetryStoreReturnRefundController::class)->whereNumber('return');
        });
    });
});
