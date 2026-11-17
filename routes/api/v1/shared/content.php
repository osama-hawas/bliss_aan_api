<?php

use App\Http\Controllers\Api\V1\Shared\Content\ListFaqsController;
use App\Http\Controllers\Api\V1\Shared\Content\ShowPageController;
use Illuminate\Support\Facades\Route;

// Slice 12b: public FAQs and pages of one app (`?app=client|provider`, D158); terms/privacy open before sign-in.
Route::middleware('auth.optional')->group(function () {
    Route::get('/faqs', ListFaqsController::class);
    Route::get('/pages/{slug}', ShowPageController::class)->where('slug', '[a-z0-9-]{1,50}');
});
