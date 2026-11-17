<?php

use App\Http\Controllers\Webhooks\FakeCheckoutController;
use App\Http\Controllers\Webhooks\HandlePaymentWebhookController;
use Illuminate\Support\Facades\Route;

// Slice 10 (D189): gateway webhooks, outside /api/v1, verified by signature (no auth). Registered in bootstrap/app.php.
Route::prefix('webhooks/payments')->group(function () {
    // Fake driver, local/testing only: the 3-D Secure stand-in page (the controller 404s otherwise).
    Route::get('/fake/checkout/{reference}', [FakeCheckoutController::class, 'show']);
    Route::post('/fake/checkout/{reference}', [FakeCheckoutController::class, 'submit']);

    Route::post('/{gateway}', HandlePaymentWebhookController::class)->where('gateway', '[a-z0-9_]+')->middleware('throttle:120,1');
});
