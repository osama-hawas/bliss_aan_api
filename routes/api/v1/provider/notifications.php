<?php

use App\Http\Controllers\Api\V1\Provider\Notifications\ListNotificationsController;
use App\Http\Controllers\Api\V1\Provider\Notifications\MarkAllReadController;
use App\Http\Controllers\Api\V1\Provider\Notifications\MarkReadController;
use App\Http\Controllers\Api\V1\Provider\Notifications\UnreadCountController;
use Illuminate\Support\Facades\Route;

// Notifications (Slice 8, docs/slice-8-contract.md §4, D137).
Route::get('/notifications', ListNotificationsController::class);
Route::get('/notifications/unread-count', UnreadCountController::class);
Route::post('/notifications/read-all', MarkAllReadController::class);
Route::post('/notifications/{notification}/read', MarkReadController::class);
