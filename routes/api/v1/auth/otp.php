<?php

use App\Http\Controllers\Api\V1\Auth\Otp\RequestOtpController;
use App\Http\Controllers\Api\V1\Auth\Otp\VerifyOtpController;
use Illuminate\Support\Facades\Route;

Route::post('/otp/request', RequestOtpController::class);
Route::post('/otp/verify', VerifyOtpController::class);
