<?php

namespace App\Http\Controllers\Api\V1\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Roles\RolePermissionsRequest;
use App\Http\Resources\Api\V1\Admin\Roles\RoleResource;
use App\Services\Staff\RoleManagement;
use App\Traits\ApiResponse;

/**
 * `PUT admin/settings/roles/{id}`: replace the permission set (D165, D166, D169).
 */
class UpdateRolePermissionsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleManagement $roles) {}

    public function __invoke(RolePermissionsRequest $request, int $role)
    {
        $saved = $this->roles->updatePermissions($request->user(), $this->roles->find($role), $request->permissions(), $request->validated('reason'));

        return $this->success(new RoleResource($saved), __('Role saved.'));
    }
}
