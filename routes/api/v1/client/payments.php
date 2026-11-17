<?php

use App\Http\Controllers\Api\V1\Client\Payments\DeletePaymentMethodController;
use App\Http\Controllers\Api\V1\Client\Payments\ListPaymentMethodsController;
use App\Http\Controllers\Api\V1\Client\Payments\MakeDefaultPaymentMethodController;
use App\Http\Controllers\Api\V1\Client\Payments\PayOfferController;
use App\Http\Controllers\Api\V1\Client\Payments\ShowPaymentController;
use App\Http\Controllers\Api\V1\Client\Payments\StorePaymentMethodController;
use Illuminate\Support\Facades\Route;

// Slice 10: saved cards (gateway tokens only, D188/D193), paying an offer (D185) and reading her own payment.
Route::get('/payment-methods', ListPaymentMethodsController::class);
Route::post('/payment-methods', StorePaymentMethodController::class);
Route::post('/payment-methods/{method}/default', MakeDefaultPaymentMethodController::class)->whereNumber('method');
Route::delete('/payment-methods/{method}', DeletePaymentMethodController::class)->whereNumber('method');

Route::post('/offers/{offer}/payments', PayOfferController::class)->whereNumber('offer');
Route::get('/payments/{payment}', ShowPaymentController::class)->whereNumber('payment');
