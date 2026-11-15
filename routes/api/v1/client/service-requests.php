<?php

use App\Http\Controllers\Api\V1\Client\Offers\ListRequestOffersController;
use App\Http\Controllers\Api\V1\Client\ServiceRequests\CancelServiceRequestController;
use App\Http\Controllers\Api\V1\Client\ServiceRequests\ListServiceRequestsController;
use App\Http\Controllers\Api\V1\Client\ServiceRequests\OpenRequestEligibilityController;
use App\Http\Controllers\Api\V1\Client\ServiceRequests\ShowAttachmentController;
use App\Http\Controllers\Api\V1\Client\ServiceRequests\ShowServiceRequestController;
use App\Http\Controllers\Api\V1\Client\ServiceRequests\StoreServiceRequestController;
use Illuminate\Support\Facades\Route;

// Service requests (BRD Req 14–18): direct and open requests, history, cancellation. Slice 4.
Route::get('/open-request-eligibility', OpenRequestEligibilityController::class);
Route::get('/service-requests', ListServiceRequestsController::class);
Route::post('/service-requests', StoreServiceRequestController::class);
Route::get('/service-requests/{serviceRequest}', ShowServiceRequestController::class)->whereNumber('serviceRequest');
Route::post('/service-requests/{serviceRequest}/cancel', CancelServiceRequestController::class)->whereNumber('serviceRequest');
Route::get('/service-requests/{serviceRequest}/attachments/{media}', ShowAttachmentController::class)->whereNumber(['serviceRequest', 'media']);
Route::get('/service-requests/{serviceRequest}/offers', ListRequestOffersController::class)->whereNumber('serviceRequest'); // Slice 5b
