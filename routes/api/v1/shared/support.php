<?php

use App\Http\Controllers\Api\V1\Shared\Support\ListSupportTicketsController;
use App\Http\Controllers\Api\V1\Shared\Support\ReplySupportTicketController;
use App\Http\Controllers\Api\V1\Shared\Support\ShowSupportTicketController;
use App\Http\Controllers\Api\V1\Shared\Support\StoreSupportTicketController;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

// Slice 12b: her own support tickets (D157–D162). Staff handling is Slice 17.
Route::middleware([...ApiRouteLoader::AUTHENTICATED, 'account.active', 'user.type:client,provider'])->group(function () {
    Route::get('/support-tickets', ListSupportTicketsController::class);
    Route::post('/support-tickets', StoreSupportTicketController::class);
    Route::get('/support-tickets/{ticket}', ShowSupportTicketController::class)->whereNumber('ticket');
    Route::post('/support-tickets/{ticket}/messages', ReplySupportTicketController::class)->whereNumber('ticket');
});
