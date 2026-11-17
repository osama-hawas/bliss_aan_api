<?php

use App\Http\Controllers\Api\V1\Shared\Onboarding\ListOnboardingSlidesController;
use Illuminate\Support\Facades\Route;

// Slice 25 (S25): onboarding slides of one app (`?app=client|provider`, D364), shown before sign-in.
Route::middleware('auth.optional')->get('/onboarding', ListOnboardingSlidesController::class);
