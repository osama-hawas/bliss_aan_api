<?php

use App\Http\Controllers\Api\V1\Admin\Support\AssignTicketController;
use App\Http\Controllers\Api\V1\Admin\Support\ChangeTicketStatusController;
use App\Http\Controllers\Api\V1\Admin\Support\ConversationPageController;
use App\Http\Controllers\Api\V1\Admin\Support\ListTicketConversationsController;
use App\Http\Controllers\Api\V1\Admin\Support\ListTicketsController;
use App\Http\Controllers\Api\V1\Admin\Support\ReviewConversationController;
use App\Http\Controllers\Api\V1\Admin\Support\ShowTicketController;
use App\Http\Controllers\Api\V1\Admin\Support\StoreStaffNoteController;
use App\Http\Controllers\Api\V1\Admin\Support\StoreStaffReplyController;
use App\Http\Controllers\Api\V1\Admin\Support\TakeTicketController;
use Illuminate\Support\Facades\Route;

// Slice 17 (slice-17-contract.md §6–§7, D258–D260): staff ticket handling and chat review via an assigned ticket.
Route::middleware('permission:support.view')->group(function () {
    Route::get('/', ListTicketsController::class);
    Route::get('/{ticket}', ShowTicketController::class)->whereNumber('ticket');
    Route::post('/{ticket}/assign', AssignTicketController::class)->whereNumber('ticket')->middleware('permission:support.assign');

    Route::middleware('permission:support.reply')->group(function () {
        Route::post('/{ticket}/take', TakeTicketController::class)->whereNumber('ticket');
        Route::post('/{ticket}/messages', StoreStaffReplyController::class)->whereNumber('ticket');
        Route::post('/{ticket}/notes', StoreStaffNoteController::class)->whereNumber('ticket');
        Route::post('/{ticket}/status', ChangeTicketStatusController::class)->whereNumber('ticket');
    });

    Route::middleware('permission:chats.view_for_ticket')->group(function () {
        Route::get('/{ticket}/conversations', ListTicketConversationsController::class)->whereNumber('ticket');
        Route::post('/{ticket}/conversations/{conversation}/review', ReviewConversationController::class)->whereNumber(['ticket', 'conversation']);
        Route::get('/{ticket}/conversations/{conversation}/messages', ConversationPageController::class)->whereNumber(['ticket', 'conversation']);
    });
});
