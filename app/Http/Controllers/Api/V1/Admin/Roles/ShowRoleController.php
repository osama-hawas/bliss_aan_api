<?php

namespace App\Http\Controllers\Api\V1\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Roles\RoleResource;
use App\Services\Staff\RoleManagement;
use App\Traits\ApiResponse;

/**
 * `GET admin/settings/roles/{id}`.
 */
class ShowRoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleManagement $roles) {}

    public function __invoke(int $role)
    {
        return $this->success(new RoleResource($this->roles->find($role)));
    }
}
