<?php

namespace App\Http\Controllers\Api\V1\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Staff\StaffReasonRequest;
use App\Http\Resources\Api\V1\Admin\Staff\StaffResource;
use App\Services\Staff\StaffManagement;
use App\Traits\ApiResponse;

/**
 * `POST admin/settings/staff/{id}/reactivate`.
 */
class ReactivateStaffController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffManagement $staff) {}

    public function __invoke(StaffReasonRequest $request, int $staff)
    {
        $member = $this->staff->reactivate($request->user(), $this->staff->find($staff), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new StaffResource($member), __('Account reactivated.'));
    }
}
