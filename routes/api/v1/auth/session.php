<?php

use App\Http\Controllers\Api\V1\Auth\Session\LogoutController;
use App\Http\Controllers\Api\V1\Auth\Session\RefreshTokenController;
use App\Http\Controllers\Api\V1\Auth\Session\ShowMeController;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

// Takes the current token even when expired (inside the refresh window), so no auth:api.
Route::post('/token/refresh', RefreshTokenController::class);

// Suspended users can still log out.
Route::post('/logout', LogoutController::class)->middleware(ApiRouteLoader::AUTHENTICATED);

Route::get('/me', ShowMeController::class)->middleware([...ApiRouteLoader::AUTHENTICATED, 'account.active']);
