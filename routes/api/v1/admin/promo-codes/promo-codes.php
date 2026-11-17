<?php

use App\Http\Controllers\Api\V1\Admin\Store\ActivatePromoCodeController;
use App\Http\Controllers\Api\V1\Admin\Store\ListPromoCodesController;
use App\Http\Controllers\Api\V1\Admin\Store\ShowPromoCodeController;
use App\Http\Controllers\Api\V1\Admin\Store\StopPromoCodeController;
use App\Http\Controllers\Api\V1\Admin\Store\StorePromoCodeController;
use App\Http\Controllers\Api\V1\Admin\Store\UpdatePromoCodeController;
use Illuminate\Support\Facades\Route;

// Slice 9b promo codes (D200, D213): products.manage.
Route::middleware('permission:products.manage')->group(function () {
    Route::get('/', ListPromoCodesController::class);
    Route::post('/', StorePromoCodeController::class);
    Route::get('/{promoCode}', ShowPromoCodeController::class)->whereNumber('promoCode');
    Route::patch('/{promoCode}', UpdatePromoCodeController::class)->whereNumber('promoCode');
    Route::post('/{promoCode}/stop', StopPromoCodeController::class)->whereNumber('promoCode');
    Route::post('/{promoCode}/activate', ActivatePromoCodeController::class)->whereNumber('promoCode');
});
