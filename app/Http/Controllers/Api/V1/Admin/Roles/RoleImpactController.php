<?php

namespace App\Http\Controllers\Api\V1\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Roles\RolePermissionsRequest;
use App\Services\Staff\RoleManagement;
use App\Traits\ApiResponse;

/**
 * `POST admin/settings/roles/{id}/impact`: affected staff and permission diff, no write (D169 checked).
 */
class RoleImpactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleManagement $roles) {}

    public function __invoke(RolePermissionsRequest $request, int $role)
    {
        return $this->success($this->roles->impact($this->roles->find($role), $request->permissions()));
    }
}
