<?php

use App\Http\Controllers\Api\V1\Admin\Providers\AcceptDocumentController;
use App\Http\Controllers\Api\V1\Admin\Providers\ActivateApplicationController;
use App\Http\Controllers\Api\V1\Admin\Providers\AddNoteController;
use App\Http\Controllers\Api\V1\Admin\Providers\ListApplicationsController;
use App\Http\Controllers\Api\V1\Admin\Providers\RejectDocumentController;
use App\Http\Controllers\Api\V1\Admin\Providers\RequestCompletionController;
use App\Http\Controllers\Api\V1\Admin\Providers\ShowApplicationController;
use Illuminate\Support\Facades\Route;

// Provider application review (Req 54). The application id is the provider id.
Route::middleware('permission:providers.review')->group(function () {
    Route::get('/', ListApplicationsController::class);
    Route::get('/{provider}', ShowApplicationController::class)->whereNumber('provider');
    Route::post('/{provider}/documents/{document}/accept', AcceptDocumentController::class)->whereNumber(['provider', 'document']);
    Route::post('/{provider}/documents/{document}/reject', RejectDocumentController::class)->whereNumber(['provider', 'document']);
    Route::post('/{provider}/notes', AddNoteController::class)->whereNumber('provider');
    Route::post('/{provider}/request-completion', RequestCompletionController::class)->whereNumber('provider');
    Route::post('/{provider}/activate', ActivateApplicationController::class)->whereNumber('provider');
});
