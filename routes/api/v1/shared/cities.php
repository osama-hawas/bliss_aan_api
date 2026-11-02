<?php

use App\Http\Controllers\Api\V1\Shared\Cities\ListCitiesController;
use Illuminate\Support\Facades\Route;

// Public: the client sign-up form needs it before there is a session.
Route::get('/cities', ListCitiesController::class);
