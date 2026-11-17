<?php

use App\Http\Controllers\Api\V1\Admin\Staff\ChangeStaffRoleController;
use App\Http\Controllers\Api\V1\Admin\Staff\CreateStaffController;
use App\Http\Controllers\Api\V1\Admin\Staff\ListStaffController;
use App\Http\Controllers\Api\V1\Admin\Staff\ReactivateStaffController;
use App\Http\Controllers\Api\V1\Admin\Staff\ShowStaffController;
use App\Http\Controllers\Api\V1\Admin\Staff\StaffRoleImpactController;
use App\Http\Controllers\Api\V1\Admin\Staff\SuspendStaffController;
use App\Http\Controllers\Api\V1\Admin\Staff\UpdateStaffController;
use Illuminate\Support\Facades\Route;

// Staff accounts (Slice 14b, D165–D170). Everything needs roles.manage.
Route::middleware('permission:roles.manage')->prefix('staff')->group(function () {
    Route::get('/', ListStaffController::class);
    Route::post('/', CreateStaffController::class);
    Route::get('/{staff}', ShowStaffController::class)->whereNumber('staff');
    Route::patch('/{staff}', UpdateStaffController::class)->whereNumber('staff');
    Route::post('/{staff}/role-impact', StaffRoleImpactController::class)->whereNumber('staff');
    Route::post('/{staff}/role', ChangeStaffRoleController::class)->whereNumber('staff');
    Route::post('/{staff}/suspend', SuspendStaffController::class)->whereNumber('staff');
    Route::post('/{staff}/reactivate', ReactivateStaffController::class)->whereNumber('staff');
});
