<?php

namespace App\Http\Controllers\Api\V1\Admin\Cancellations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Cancellations\AdminCancellationResource;
use App\Services\Cancellations\CancellationQueries;
use App\Traits\ApiResponse;

/**
 * One cancellation request with its booking (bookings.view).
 */
class ShowCancellationRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CancellationQueries $queries) {}

    public function __invoke(int $cancellation)
    {
        return $this->success(new AdminCancellationResource($this->queries->adminOne($cancellation)));
    }
}
