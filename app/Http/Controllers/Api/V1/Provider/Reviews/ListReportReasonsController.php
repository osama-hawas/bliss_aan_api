<?php

namespace App\Http\Controllers\Api\V1\Provider\Reviews;

use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;

/**
 * Report reason codes.
 */
class ListReportReasonsController extends Controller
{
    use ApiResponse;

    public function __construct() {}

    public function __invoke()
    {
        return $this->success(array_map(fn (ReportReason $r) => ['code' => $r->value, 'label' => $r->label()], ReportReason::cases()));
    }
}
