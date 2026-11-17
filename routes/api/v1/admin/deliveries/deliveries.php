<?php

use App\Http\Controllers\Api\V1\Admin\Store\ListDeliveriesController;
use Illuminate\Support\Facades\Route;

// Slice 9b: the internal deliveries assigned to the calling officer.
Route::get('/', ListDeliveriesController::class)->middleware('permission:delivery.assigned');
