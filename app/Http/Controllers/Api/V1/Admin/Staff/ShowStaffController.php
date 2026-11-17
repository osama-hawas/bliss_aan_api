<?php

namespace App\Http\Controllers\Api\V1\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Staff\StaffDetailResource;
use App\Services\Staff\StaffManagement;
use App\Traits\ApiResponse;

/**
 * `GET admin/settings/staff/{id}` with status history.
 */
class ShowStaffController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffManagement $staff) {}

    public function __invoke(int $staff)
    {
        return $this->success(new StaffDetailResource($this->staff->find($staff)));
    }
}
