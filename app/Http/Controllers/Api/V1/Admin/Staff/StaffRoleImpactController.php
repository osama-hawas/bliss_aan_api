<?php

namespace App\Http\Controllers\Api\V1\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Staff\StaffRoleRequest;
use App\Models\Role;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffManagement;
use App\Traits\ApiResponse;

/**
 * `POST admin/settings/staff/{id}/role-impact`: permissions gained and lost, no write.
 */
class StaffRoleImpactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffManagement $staff) {}

    public function __invoke(StaffRoleRequest $request, int $staff)
    {
        $role = Role::findByName($request->validated('role'), StaffAccess::GUARD);

        return $this->success($this->staff->roleImpact($this->staff->find($staff), $role));
    }
}
