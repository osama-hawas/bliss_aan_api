<?php

use App\Http\Controllers\Api\V1\Provider\Team\ListTeamMembersController;
use App\Http\Controllers\Api\V1\Provider\Team\SetBookingTeamController;
use App\Http\Controllers\Api\V1\Provider\Team\ShowTeamMemberController;
use App\Http\Controllers\Api\V1\Provider\Team\StoreTeamMemberController;
use App\Http\Controllers\Api\V1\Provider\Team\UpdateTeamMemberController;
use Illuminate\Support\Facades\Route;

// Slice 13 (docs/slice-13-contract.md): her team (records only, D230) and the team of a booking (provider only).
// No delete route: a member is disabled, never deleted.
Route::get('/team-members', ListTeamMembersController::class);
Route::post('/team-members', StoreTeamMemberController::class);
Route::get('/team-members/{member}', ShowTeamMemberController::class)->whereNumber('member');
Route::patch('/team-members/{member}', UpdateTeamMemberController::class)->whereNumber('member');
Route::put('/bookings/{booking}/team', SetBookingTeamController::class)->whereNumber('booking');
