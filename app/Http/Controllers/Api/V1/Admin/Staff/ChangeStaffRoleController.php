<?php

namespace App\Http\Controllers\Api\V1\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Staff\StaffRoleRequest;
use App\Http\Resources\Api\V1\Admin\Staff\StaffResource;
use App\Models\Role;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffManagement;
use App\Traits\ApiResponse;

/**
 * `POST admin/settings/staff/{id}/role` (D165–D167).
 */
class ChangeStaffRoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffManagement $staff) {}

    public function __invoke(StaffRoleRequest $request, int $staff)
    {
        $role = Role::findByName($request->validated('role'), StaffAccess::GUARD);
        $member = $this->staff->changeRole($request->user(), $this->staff->find($staff), $role, $request->validated('reason'));

        return $this->success(new StaffResource($member), __('Role changed.'));
    }
}
