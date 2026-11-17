<?php

use App\Http\Controllers\Api\V1\Shared\Account\ShowDeletionRequestController;
use App\Http\Controllers\Api\V1\Shared\Account\StoreDeletionRequestController;
use App\Http\Controllers\Api\V1\Shared\Account\UpdateProfileController;
use App\Http\Controllers\Api\V1\Shared\Account\WithdrawDeletionRequestController;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

// Slice 12a: her own account details (D152) and account deletion request (Req 06, D150, D151, D155).
Route::middleware([...ApiRouteLoader::AUTHENTICATED, 'account.active', 'user.type:client,provider'])->group(function () {
    Route::patch('/account/profile', UpdateProfileController::class);
    Route::get('/account/deletion-request', ShowDeletionRequestController::class);
    Route::post('/account/deletion-request', StoreDeletionRequestController::class);
    Route::post('/account/deletion-request/withdraw', WithdrawDeletionRequestController::class);
});
