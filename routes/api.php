<?php

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    ApiRouteLoader::load(__DIR__.'/api/v1');
});

// Unknown API URLs still run the api middleware (locale) and return NOT_FOUND.
Route::fallback(fn () => throw new ApiException(ErrorCode::NotFound));
