<?php

namespace App\Http\Controllers\Api\V1\Shared\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Providers\ProviderAvailabilityRequest;
use App\Models\Provider;
use App\Services\Availability\ProviderAvailability;
use App\Traits\ApiResponse;

/**
 * Public: the Riyadh dates a visible provider is fully unavailable (D86, D92, D93). Dates only.
 */
class ShowProviderAvailabilityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderAvailability $availability) {}

    public function __invoke(ProviderAvailabilityRequest $request, int $provider)
    {
        $visible = Provider::query()->visibleToClients()->findOrFail($provider);
        $from = $request->validated('from');
        $to = $request->validated('to');

        return $this->success([
            'provider_id' => $visible->id,
            'from' => $from,
            'to' => $to,
            'unavailable_dates' => $this->availability->unavailableDates($visible, $from, $to),
        ]);
    }
}
