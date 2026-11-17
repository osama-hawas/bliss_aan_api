<?php

use App\Http\Controllers\Api\V1\Client\Account\SetAvatarController;
use Illuminate\Support\Facades\Route;

// Slice 12a: her profile photo (D153). A provider's photo stays on `provider/profile/avatar`.
Route::put('/account/avatar', SetAvatarController::class);
