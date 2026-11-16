<?php

namespace App\Http\Controllers\Api\V1\Admin\Cancellations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Cancellations\DecideCancellationRequest;
use App\Http\Resources\Api\V1\Admin\Cancellations\AdminCancellationResource;
use App\Services\Cancellations\BookingCancellations;
use App\Services\Cancellations\CancellationQueries;
use App\Traits\ApiResponse;

/**
 * Approve or reject (cancellations.decide, D131). Accept cancels the booking and the request (D128).
 */
class DecideCancellationRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CancellationQueries $queries,
        private readonly BookingCancellations $service,
    ) {}

    public function __invoke(DecideCancellationRequest $request, int $cancellation)
    {
        $this->service->decide($request->user(), $this->queries->adminOne($cancellation), $request->validated('decision') === 'accept',
            $request->validated('decision_reason'), $request->validated('internal_note'));

        return $this->success(new AdminCancellationResource($this->queries->adminOne($cancellation)), __('Decision saved.'));
    }
}
