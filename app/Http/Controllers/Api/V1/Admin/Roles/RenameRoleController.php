<?php

namespace App\Http\Controllers\Api\V1\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Roles\RenameRoleRequest;
use App\Http\Resources\Api\V1\Admin\Roles\RoleResource;
use App\Services\Staff\RoleManagement;
use App\Traits\ApiResponse;

/**
 * `PATCH admin/settings/roles/{id}`: custom roles only (D168).
 */
class RenameRoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleManagement $roles) {}

    public function __invoke(RenameRoleRequest $request, int $role)
    {
        $saved = $this->roles->rename($request->user(), $this->roles->find($role), $request->validated('label'), $request->validated('reason'));

        return $this->success(new RoleResource($saved), __('Role saved.'));
    }
}
