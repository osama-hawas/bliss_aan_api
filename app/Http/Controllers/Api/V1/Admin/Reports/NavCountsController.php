<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\NavCounts;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `GET admin/nav-counts`: sidebar badges for the queues she can act on (D327).
 */
class NavCountsController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, NavCounts $counts)
    {
        return $this->success($counts->for($request->user()));
    }
}
