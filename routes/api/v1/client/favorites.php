<?php

use App\Http\Controllers\Api\V1\Client\Favorites\AddFavoriteController;
use App\Http\Controllers\Api\V1\Client\Favorites\ListFavoritesController;
use App\Http\Controllers\Api\V1\Client\Favorites\RemoveFavoriteController;
use Illuminate\Support\Facades\Route;

// {type} is bound to App\Enums\FavoriteType: an unknown type is a 404.
Route::get('/favorites', ListFavoritesController::class);
Route::put('/favorites/{type}/{id}', AddFavoriteController::class)->whereNumber('id');
Route::delete('/favorites/{type}/{id}', RemoveFavoriteController::class)->whereNumber('id');
