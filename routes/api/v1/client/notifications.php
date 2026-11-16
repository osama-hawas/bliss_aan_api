<?php

use App\Http\Controllers\Api\V1\Client\Notifications\ListNotificationsController;
use App\Http\Controllers\Api\V1\Client\Notifications\MarkAllReadController;
use App\Http\Controllers\Api\V1\Client\Notifications\MarkReadController;
use App\Http\Controllers\Api\V1\Client\Notifications\UnreadCountController;
use Illuminate\Support\Facades\Route;

// Notifications (Slice 8, docs/slice-8-contract.md §4, D137).
Route::get('/notifications', ListNotificationsController::class);
Route::get('/notifications/unread-count', UnreadCountController::class);
Route::post('/notifications/read-all', MarkAllReadController::class);
Route::post('/notifications/{notification}/read', MarkReadController::class);
