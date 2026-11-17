<?php

use App\Http\Controllers\Api\V1\Admin\Providers\HidePortfolioItemController;
use App\Http\Controllers\Api\V1\Admin\Providers\ListProvidersController;
use App\Http\Controllers\Api\V1\Admin\Providers\RestorePortfolioItemController;
use App\Http\Controllers\Api\V1\Admin\Providers\SetFeaturedController;
use App\Http\Controllers\Api\V1\Admin\Providers\SetVerifiedController;
use App\Http\Controllers\Api\V1\Admin\Providers\ShowProviderController;
use Illuminate\Support\Facades\Route;

// Slice 15: activated providers (list, detail), badge and featured flags (D175), portfolio moderation (D176).
Route::get('/', ListProvidersController::class)->middleware('permission:providers.view');
Route::get('/{provider}', ShowProviderController::class)->whereNumber('provider')->middleware('permission:providers.view');
Route::post('/{provider}/verification', SetVerifiedController::class)->whereNumber('provider')->middleware('permission:providers.verify');
Route::post('/{provider}/featured', SetFeaturedController::class)->whereNumber('provider')->middleware('permission:providers.verify');
Route::post('/{provider}/portfolio/{item}/hide', HidePortfolioItemController::class)->whereNumber(['provider', 'item'])->middleware('permission:providers.review');
Route::post('/{provider}/portfolio/{item}/restore', RestorePortfolioItemController::class)->whereNumber(['provider', 'item'])->middleware('permission:providers.review');
