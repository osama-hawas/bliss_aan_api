<?php

namespace App\Http\Controllers\Api\V1\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Staff\CreateStaffRequest;
use App\Http\Resources\Api\V1\Admin\Staff\StaffResource;
use App\Models\Role;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffManagement;
use App\Traits\ApiResponse;

/**
 * `POST admin/settings/staff` (D170).
 */
class CreateStaffController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffManagement $staff) {}

    public function __invoke(CreateStaffRequest $request)
    {
        $role = Role::findByName($request->validated('role'), StaffAccess::GUARD);
        $member = $this->staff->create($request->user(), [
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
        ], $role);

        return $this->success(new StaffResource($member), __('Staff member added.'), 201);
    }
}
