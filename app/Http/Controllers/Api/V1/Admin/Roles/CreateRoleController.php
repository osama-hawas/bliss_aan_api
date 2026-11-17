<?php

namespace App\Http\Controllers\Api\V1\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Roles\CreateRoleRequest;
use App\Http\Resources\Api\V1\Admin\Roles\RoleResource;
use App\Services\Staff\RoleManagement;
use App\Traits\ApiResponse;

/**
 * `POST admin/settings/roles`: a custom role (D168).
 */
class CreateRoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleManagement $roles) {}

    public function __invoke(CreateRoleRequest $request)
    {
        $role = $this->roles->create($request->user(), $request->validated('name'), $request->validated('label'), $request->permissions(), $request->validated('reason'));

        return $this->success(new RoleResource($role), __('Role created.'), 201);
    }
}
