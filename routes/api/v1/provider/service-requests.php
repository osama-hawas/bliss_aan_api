<?php

use App\Http\Controllers\Api\V1\Provider\ServiceRequests\DeclineServiceRequestController;
use App\Http\Controllers\Api\V1\Provider\ServiceRequests\ListDeclineReasonsController;
use App\Http\Controllers\Api\V1\Provider\ServiceRequests\ListInboxController;
use App\Http\Controllers\Api\V1\Provider\ServiceRequests\ShowAttachmentController;
use App\Http\Controllers\Api\V1\Provider\ServiceRequests\ShowInboxRequestController;
use Illuminate\Support\Facades\Route;

// Provider inbox: direct requests and open opportunities, details, decline (Req 19–20). Slice 4.
Route::get('/decline-reasons', ListDeclineReasonsController::class);
Route::get('/service-requests', ListInboxController::class);
Route::get('/service-requests/{serviceRequest}', ShowInboxRequestController::class)->whereNumber('serviceRequest');
Route::post('/service-requests/{serviceRequest}/decline', DeclineServiceRequestController::class)->whereNumber('serviceRequest');
Route::get('/service-requests/{serviceRequest}/attachments/{media}', ShowAttachmentController::class)->whereNumber(['serviceRequest', 'media']);
