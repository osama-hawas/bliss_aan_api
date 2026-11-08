<?php

use App\Http\Controllers\Api\V1\Provider\Setup\AddPortfolioController;
use App\Http\Controllers\Api\V1\Provider\Setup\AddServiceController;
use App\Http\Controllers\Api\V1\Provider\Setup\ListCatalogController;
use App\Http\Controllers\Api\V1\Provider\Setup\ListServicesController;
use App\Http\Controllers\Api\V1\Provider\Setup\RemovePortfolioController;
use App\Http\Controllers\Api\V1\Provider\Setup\SetAddonController;
use App\Http\Controllers\Api\V1\Provider\Setup\SetAvatarController;
use App\Http\Controllers\Api\V1\Provider\Setup\SetInclusionsController;
use App\Http\Controllers\Api\V1\Provider\Setup\SetWorkingHoursController;
use App\Http\Controllers\Api\V1\Provider\Setup\ShowProfileController;
use App\Http\Controllers\Api\V1\Provider\Setup\UpdateProfileController;
use App\Http\Controllers\Api\V1\Provider\Setup\UpdateServiceController;
use Illuminate\Support\Facades\Route;

// Setup of an active provider (Req 32, 33): services and prices, inclusions, add-ons, hours, profile. Slice 3.
Route::get('/catalog', ListCatalogController::class);
Route::get('/services', ListServicesController::class);
Route::post('/services', AddServiceController::class);
Route::patch('/services/{service}', UpdateServiceController::class)->whereNumber('service');
Route::put('/services/{service}/inclusions', SetInclusionsController::class)->whereNumber('service');
Route::put('/addons/{addon}', SetAddonController::class)->whereNumber('addon');
Route::get('/profile', ShowProfileController::class);
Route::patch('/profile', UpdateProfileController::class);
Route::put('/profile/avatar', SetAvatarController::class);
Route::put('/working-hours', SetWorkingHoursController::class);
Route::post('/portfolio', AddPortfolioController::class);
Route::delete('/portfolio/{photo}', RemovePortfolioController::class)->whereNumber('photo');
