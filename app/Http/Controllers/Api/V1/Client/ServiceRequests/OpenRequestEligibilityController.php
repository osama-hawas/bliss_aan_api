<?php

namespace App\Http\Controllers\Api\V1\Client\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\ServiceRequests\OpenRequestEligibilityRequest;
use App\Services\ServiceRequests\RequestMatcher;
use App\Traits\ApiResponse;

/**
 * Hint for the book flow (§7.3, D65): whether an open request would find at least one provider now. Only a boolean;
 * creation runs the matching again.
 */
class OpenRequestEligibilityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RequestMatcher $matcher) {}

    public function __invoke(OpenRequestEligibilityRequest $request)
    {
        $serviceId = (int) $request->validated('service_id');
        $cityId = (int) $request->validated('city_id');
        $addonIds = array_map('intval', $request->validated('addon_ids') ?? []);

        return $this->success([
            'service_id' => $serviceId,
            'city_id' => $cityId,
            'open_request_available' => $this->matcher->openRequestAvailable($serviceId, $cityId, $addonIds),
        ]);
    }
}
