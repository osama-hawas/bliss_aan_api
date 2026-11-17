<?php

use App\Http\Controllers\Api\V1\Admin\Store\RetryShipmentController;
use App\Http\Controllers\Api\V1\Admin\Store\UpdateShipmentStatusController;
use Illuminate\Support\Facades\Route;

// Slice 9b shipments. Status: orders.update_status, or the assigned officer with delivery.assigned (checked by
// ShipmentService; anyone else gets 404).
Route::post('/{shipment}/retry', RetryShipmentController::class)->whereNumber('shipment')->middleware('permission:orders.update_status');
Route::post('/{shipment}/status', UpdateShipmentStatusController::class)->whereNumber('shipment')
    ->middleware('permission:orders.update_status|delivery.assigned');
