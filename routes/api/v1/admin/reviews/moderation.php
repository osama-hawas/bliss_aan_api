<?php

use App\Http\Controllers\Api\V1\Admin\Reviews\HideReviewController;
use App\Http\Controllers\Api\V1\Admin\Reviews\ListReviewsController;
use App\Http\Controllers\Api\V1\Admin\Reviews\RestoreReviewController;
use Illuminate\Support\Facades\Route;

// Slice 17 (slice-17-contract.md §8, D261): review moderation.
Route::middleware('permission:reviews.moderate')->group(function () {
    Route::get('/', ListReviewsController::class);
    Route::post('/{review}/hide', HideReviewController::class)->whereNumber('review');
    Route::post('/{review}/restore', RestoreReviewController::class)->whereNumber('review');
});
