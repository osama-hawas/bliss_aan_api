<?php

use App\Http\Controllers\Api\V1\Auth\Register\RegisterController;
use Illuminate\Support\Facades\Route;

Route::post('/register', RegisterController::class);
