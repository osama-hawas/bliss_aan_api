<?php

use App\Http\Controllers\Api\V1\Admin\Roles\CreateRoleController;
use App\Http\Controllers\Api\V1\Admin\Roles\DeleteRoleController;
use App\Http\Controllers\Api\V1\Admin\Roles\ListRolesController;
use App\Http\Controllers\Api\V1\Admin\Roles\RenameRoleController;
use App\Http\Controllers\Api\V1\Admin\Roles\RoleImpactController;
use App\Http\Controllers\Api\V1\Admin\Roles\ShowRoleController;
use App\Http\Controllers\Api\V1\Admin\Roles\UpdateRolePermissionsController;
use Illuminate\Support\Facades\Route;

// Roles and permission sets (Slice 14b, D164–D169). Everything needs roles.manage.
Route::middleware('permission:roles.manage')->prefix('roles')->group(function () {
    Route::get('/', ListRolesController::class);
    Route::post('/', CreateRoleController::class);
    Route::get('/{role}', ShowRoleController::class)->whereNumber('role');
    Route::post('/{role}/impact', RoleImpactController::class)->whereNumber('role');
    Route::put('/{role}', UpdateRolePermissionsController::class)->whereNumber('role');
    Route::patch('/{role}', RenameRoleController::class)->whereNumber('role');
    Route::delete('/{role}', DeleteRoleController::class)->whereNumber('role');
});
