<?php

use App\Http\Controllers\Api\V1\Client\Chat\ListConversationsController;
use App\Http\Controllers\Api\V1\Client\Chat\ListMessagesController;
use App\Http\Controllers\Api\V1\Client\Chat\MarkReadController;
use App\Http\Controllers\Api\V1\Client\Chat\OpenConversationController;
use App\Http\Controllers\Api\V1\Client\Chat\SendMessageController;
use App\Http\Controllers\Api\V1\Client\Chat\ShowConversationController;
use Illuminate\Support\Facades\Route;

// Chat (Req 37–40, 43–44). Slice 7, docs/slice-7-contract.md.
Route::post('/service-requests/{serviceRequest}/conversation', OpenConversationController::class)->whereNumber('serviceRequest');
Route::get('/conversations', ListConversationsController::class);
Route::get('/conversations/{conversation}', ShowConversationController::class)->whereNumber('conversation');
Route::get('/conversations/{conversation}/messages', ListMessagesController::class)->whereNumber('conversation');
Route::post('/conversations/{conversation}/messages', SendMessageController::class)->whereNumber('conversation');
Route::post('/conversations/{conversation}/read', MarkReadController::class)->whereNumber('conversation');
