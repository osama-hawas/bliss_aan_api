<?php

use App\Http\Controllers\Api\V1\Admin\Providers\ReactivateProviderController;
use App\Http\Controllers\Api\V1\Admin\Providers\SuspendProviderController;
use Illuminate\Support\Facades\Route;

// Provider standing (Req 08, 54). The rest of the providers module comes with Slice 15.
Route::middleware('permission:providers.suspend')->group(function () {
    Route::post('/{provider}/suspend', SuspendProviderController::class)->whereNumber('provider');
    Route::post('/{provider}/reactivate', ReactivateProviderController::class)->whereNumber('provider');
});
