<?php

namespace App\Http\Controllers\Api\V1\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Staff\ListStaffRequest;
use App\Http\Resources\Api\V1\Admin\Staff\StaffResource;
use App\Services\Staff\StaffManagement;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `GET admin/settings/staff`: search by name/phone, filter by role and status.
 */
class ListStaffController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffManagement $staff) {}

    public function __invoke(ListStaffRequest $request)
    {
        $page = $this->staff->list($request->filters(), $request->validated('sort'), Pagination::perPage($request));

        return $this->success(StaffResource::collection($page));
    }
}
