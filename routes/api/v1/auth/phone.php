<?php

use App\Http\Controllers\Api\V1\Auth\Phone\ChangePhoneController;
use App\Http\Controllers\Api\V1\Auth\Phone\RequestPhoneChangeOtpController;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

Route::middleware([...ApiRouteLoader::AUTHENTICATED, 'account.active', 'user.type:client,provider'])->group(function () {
    Route::post('/change-phone/otp', RequestPhoneChangeOtpController::class);
    Route::post('/change-phone', ChangePhoneController::class);
});
