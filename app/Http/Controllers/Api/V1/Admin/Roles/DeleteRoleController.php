<?php

namespace App\Http\Controllers\Api\V1\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Roles\DeleteRoleRequest;
use App\Services\Staff\RoleManagement;
use App\Traits\ApiResponse;

/**
 * `DELETE admin/settings/roles/{id}`: an unused custom role only (D168).
 */
class DeleteRoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleManagement $roles) {}

    public function __invoke(DeleteRoleRequest $request, int $role)
    {
        $this->roles->delete($request->user(), $this->roles->find($role), $request->validated('reason'));

        return response()->noContent();
    }
}
