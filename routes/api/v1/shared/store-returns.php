<?php

use App\Http\Controllers\Api\V1\Shared\Store\ShowReturnPolicyController;
use Illuminate\Support\Facades\Route;

// S23 (D348): the store return policy text, open before sign-in like the catalogue.
Route::middleware('auth.optional')->get('/store/return-policy', ShowReturnPolicyController::class);
