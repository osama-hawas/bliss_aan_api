<?php

use App\Http\Controllers\Api\V1\Admin\Store\CancelStoreOrderController;
use App\Http\Controllers\Api\V1\Admin\Store\CreateShipmentController;
use App\Http\Controllers\Api\V1\Admin\Store\ListDeliveryOfficersController;
use App\Http\Controllers\Api\V1\Admin\Store\ListStoreOrdersController;
use App\Http\Controllers\Api\V1\Admin\Store\PrepareStoreOrderController;
use App\Http\Controllers\Api\V1\Admin\Store\ShowStoreOrderController;
use Illuminate\Support\Facades\Route;

// Slice 9b store orders (D211, D213): reading needs orders.view; every action also orders.update_status.
Route::middleware('permission:orders.view')->group(function () {
    Route::get('/', ListStoreOrdersController::class);
    Route::get('/{order}', ShowStoreOrderController::class)->whereNumber('order');

    Route::middleware('permission:orders.update_status')->group(function () {
        Route::get('/delivery-officers', ListDeliveryOfficersController::class);
        Route::post('/{order}/prepare', PrepareStoreOrderController::class)->whereNumber('order');
        Route::post('/{order}/cancel', CancelStoreOrderController::class)->whereNumber('order');
        Route::post('/{order}/shipments', CreateShipmentController::class)->whereNumber('order');
    });
});
