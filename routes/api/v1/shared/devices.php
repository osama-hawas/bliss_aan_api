<?php

use App\Http\Controllers\Api\V1\Shared\Devices\ListDevicesController;
use App\Http\Controllers\Api\V1\Shared\Devices\LogoutOtherDevicesController;
use App\Http\Controllers\Api\V1\Shared\Devices\UpdateFcmTokenController;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

Route::middleware([...ApiRouteLoader::AUTHENTICATED, 'account.active', 'user.type:client,provider'])->group(function () {
    Route::get('/devices', ListDevicesController::class);
    Route::post('/devices/logout-others', LogoutOtherDevicesController::class);
    Route::put('/devices/current/fcm-token', UpdateFcmTokenController::class);
});
