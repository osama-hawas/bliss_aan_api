<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['success' => true, 'data' => 'pong']));
