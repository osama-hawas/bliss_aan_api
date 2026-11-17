<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Reports\UpcomingBookingResource;
use App\Services\Reports\Overview;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `GET admin/overview` (`dashboard.view`): queue, figures, trends and upcoming bookings, scoped by role (D328).
 */
class OverviewController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, Overview $overview)
    {
        $data = $overview->for($request->user());
        $data['upcoming'] = UpcomingBookingResource::collection($data['upcoming']);

        return $this->success($data);
    }
}
