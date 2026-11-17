<?php

use App\Http\Controllers\Api\V1\Admin\Operations\AddServiceRequestNoteController;
use App\Http\Controllers\Api\V1\Admin\Operations\CancelServiceRequestController;
use Illuminate\Support\Facades\Route;

// Slice 17 (slice-17-contract.md §4, D256): staff cancel an open request and add internal notes.
Route::middleware(['permission:bookings.view', 'permission:bookings.intervene'])->group(function () {
    Route::post('/{serviceRequest}/cancel', CancelServiceRequestController::class)->whereNumber('serviceRequest');
    Route::post('/{serviceRequest}/notes', AddServiceRequestNoteController::class)->whereNumber('serviceRequest');
});
