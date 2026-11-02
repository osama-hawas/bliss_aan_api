<?php

use App\Http\Controllers\Api\V1\Shared\Media\DownloadMediaController;
use App\Http\Controllers\Api\V1\Shared\Media\StoreUploadController;
use App\Routing\ApiRouteLoader;
use Illuminate\Support\Facades\Route;

Route::post('/uploads', StoreUploadController::class)
    ->middleware([...ApiRouteLoader::AUTHENTICATED, 'account.active']);

Route::get('/media/{media}', DownloadMediaController::class)
    ->middleware('signed')
    ->name('media.download');
