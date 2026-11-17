<?php

use App\Http\Controllers\Api\V1\Admin\Settings as S;
use Illuminate\Support\Facades\Route;

// Slice 20 (S20) — app settings, cities, document types, policy versions. All settings.manage (D307–D311).
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('/app', S\ListAppSettingsController::class);
    Route::put('/app/{key}', S\UpdateAppSettingController::class)->where('key', '[a-z0-9_]+(\.[a-z0-9_]+)+');

    Route::prefix('cities')->group(function () {
        Route::get('/', S\ListCitiesController::class);
        Route::post('/', S\CreateCityController::class);
        Route::put('/{city}', S\UpdateCityController::class)->whereNumber('city');
        Route::post('/{city}/impact', S\CityImpactController::class)->whereNumber('city');
        Route::post('/{city}/stop', S\StopCityController::class)->whereNumber('city');
        Route::post('/{city}/activate', S\ActivateCityController::class)->whereNumber('city');
    });

    Route::prefix('document-types')->group(function () {
        Route::get('/', S\ListDocumentTypesController::class);
        Route::post('/', S\CreateDocumentTypeController::class);
        Route::put('/{documentType}', S\UpdateDocumentTypeController::class)->whereNumber('documentType');
        Route::post('/{documentType}/impact', S\DocumentTypeImpactController::class)->whereNumber('documentType');
        Route::post('/{documentType}/stop', S\StopDocumentTypeController::class)->whereNumber('documentType');
        Route::post('/{documentType}/activate', S\ActivateDocumentTypeController::class)->whereNumber('documentType');
    });

    Route::get('/policies', S\ListPolicyVersionsController::class);
    Route::post('/policies', S\PublishPolicyController::class);
});
