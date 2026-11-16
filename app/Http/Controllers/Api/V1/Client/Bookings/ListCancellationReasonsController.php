<?php

namespace App\Http\Controllers\Api\V1\Client\Bookings;

use App\Enums\CancellationReason;
use App\Enums\CancellationRequesterRole;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;

/**
 * Cancellation reason codes for the client app.
 */
class ListCancellationReasonsController extends Controller
{
    use ApiResponse;

    public function __construct() {}

    public function __invoke()
    {
        return $this->success(array_map(fn (CancellationReason $r) => ['code' => $r->value, 'label' => $r->label()], CancellationReason::for(CancellationRequesterRole::Client)));
    }
}
