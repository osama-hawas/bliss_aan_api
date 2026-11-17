<?php

use App\Http\Controllers\Api\V1\Admin\Onboarding as O;
use Illuminate\Support\Facades\Route;

// Slice 25 (S25) — onboarding slides of the client / provider apps (content.manage). D360–D365. No delete.
Route::middleware('permission:content.manage')->prefix('onboarding-slides')->group(function () {
    Route::get('/', O\ListOnboardingSlidesController::class);
    Route::post('/', O\CreateOnboardingSlideController::class);
    Route::get('/{slide}', O\ShowOnboardingSlideController::class)->whereNumber('slide');
    Route::put('/{slide}', O\UpdateOnboardingSlideController::class)->whereNumber('slide');
    Route::post('/{slide}/show', O\PublishOnboardingSlideController::class)->whereNumber('slide');
    Route::post('/{slide}/hide', O\HideOnboardingSlideController::class)->whereNumber('slide');
    Route::post('/{slide}/move', O\MoveOnboardingSlideController::class)->whereNumber('slide');
});
