<?php

use App\Http\Controllers\Api\V1\Client\Offers\ShowOfferController;
use Illuminate\Support\Facades\Route;

// Offers on the client's own requests (Req 19). Slice 5b.
Route::get('/offers/{offer}', ShowOfferController::class)->whereNumber('offer');
