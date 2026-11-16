<?php

use App\Http\Controllers\Api\V1\Shared\Reviews\ListProviderReviewsController;
use App\Http\Controllers\Api\V1\Shared\Reviews\ListReviewTagsController;
use Illuminate\Support\Facades\Route;

// Public reviews (Slice 6, D75, D129): guests can read them, like the rest of discovery.
Route::middleware('auth.optional')->group(function () {
    Route::get('/providers/{provider}/reviews', ListProviderReviewsController::class)->whereNumber('provider');
    Route::get('/review-tags', ListReviewTagsController::class);
});
