<?php

use App\Http\Controllers\Api\V1\Provider\Onboarding\AddPhotoController;
use App\Http\Controllers\Api\V1\Provider\Onboarding\AttachDocumentController;
use App\Http\Controllers\Api\V1\Provider\Onboarding\RemoveDocumentController;
use App\Http\Controllers\Api\V1\Provider\Onboarding\RemovePhotoController;
use App\Http\Controllers\Api\V1\Provider\Onboarding\ShowApplicationController;
use App\Http\Controllers\Api\V1\Provider\Onboarding\SubmitApplicationController;
use App\Http\Controllers\Api\V1\Provider\Onboarding\UpdateApplicationController;
use Illuminate\Support\Facades\Route;

// Her own application only (the provider is always the signed-in user's). Slice 3, Req 03.
Route::get('/application', ShowApplicationController::class);
Route::put('/application', UpdateApplicationController::class);
Route::post('/application/submit', SubmitApplicationController::class);
Route::post('/application/documents', AttachDocumentController::class);
Route::delete('/application/documents/{document}', RemoveDocumentController::class)->whereNumber('document');
Route::post('/application/photos', AddPhotoController::class);
Route::delete('/application/photos/{photo}', RemovePhotoController::class)->whereNumber('photo');
