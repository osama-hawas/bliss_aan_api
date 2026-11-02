<?php

use App\Http\Controllers\Api\V1\Shared\Media\DownloadMediaController;
use App\Http\Controllers\Api\V1\Shared\Media\StoreUploadController;
use Illuminate\Support\Facades\Route;

Route::post('/uploads', StoreUploadController::class)
    ->middleware(['auth:api', 'account.active']);

Route::get('/media/{media}', DownloadMediaController::class)
    ->middleware('signed')
    ->name('media.download');
