<?php

use App\Http\Controllers\Api\V1\Provider\Calendar\CreateBlockController;
use App\Http\Controllers\Api\V1\Provider\Calendar\DeleteBlockController;
use App\Http\Controllers\Api\V1\Provider\Calendar\ShowCalendarController;
use Illuminate\Support\Facades\Route;

// Calendar and availability blocks (Req 17). Slice 5a.
Route::get('/calendar', ShowCalendarController::class);
Route::post('/availability-blocks', CreateBlockController::class);
Route::delete('/availability-blocks/{block}', DeleteBlockController::class)->whereNumber('block');
