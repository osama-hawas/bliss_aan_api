<?php

use App\Http\Controllers\Api\V1\Admin\ServiceRequests\ListServiceRequestsController;
use App\Http\Controllers\Api\V1\Admin\ServiceRequests\ShowAttachmentController;
use App\Http\Controllers\Api\V1\Admin\ServiceRequests\ShowServiceRequestController;
use Illuminate\Support\Facades\Route;

// Service requests, read-only (Slice 4, D57/D64). No write routes until Slice 17.
Route::middleware('permission:bookings.view')->group(function () {
    Route::get('/', ListServiceRequestsController::class);
    Route::get('/{serviceRequest}', ShowServiceRequestController::class)->whereNumber('serviceRequest');
    Route::get('/{serviceRequest}/attachments/{media}', ShowAttachmentController::class)->whereNumber(['serviceRequest', 'media']);
});
