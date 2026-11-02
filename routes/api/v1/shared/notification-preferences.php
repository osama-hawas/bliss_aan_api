<?php

use App\Http\Controllers\Api\V1\Shared\NotificationPreferences\ShowNotificationPreferencesController;
use App\Http\Controllers\Api\V1\Shared\NotificationPreferences\UpdateNotificationPreferencesController;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

Route::middleware([...ApiRouteLoader::AUTHENTICATED, 'account.active', 'user.type:client,provider'])->group(function () {
    Route::get('/notification-preferences', ShowNotificationPreferencesController::class);
    Route::put('/notification-preferences', UpdateNotificationPreferencesController::class);
});
