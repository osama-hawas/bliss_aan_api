<?php

namespace App\Http\Controllers\Api\V1\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Staff\UpdateStaffRequest;
use App\Http\Resources\Api\V1\Admin\Staff\StaffResource;
use App\Services\Staff\StaffManagement;
use App\Traits\ApiResponse;

/**
 * `PATCH admin/settings/staff/{id}`: name and email only.
 */
class UpdateStaffController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffManagement $staff) {}

    public function __invoke(UpdateStaffRequest $request, int $staff)
    {
        $member = $this->staff->update($request->user(), $this->staff->find($staff), $request->validated());

        return $this->success(new StaffResource($member));
    }
}
