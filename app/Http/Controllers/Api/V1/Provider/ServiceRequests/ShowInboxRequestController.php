<?php

namespace App\Http\Controllers\Api\V1\Provider\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\ServiceRequests\ProviderRequestResource;
use App\Services\Providers\ProviderSetup;
use App\Services\ServiceRequests\ServiceRequestLifecycle;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One request she received; the first view sets `seen_at`.
 */
class ShowInboxRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly ServiceRequestQueries $queries,
        private readonly ServiceRequestLifecycle $lifecycle,
    ) {}

    public function __invoke(Request $request, int $serviceRequest)
    {
        $provider = $this->setup->activeProvider($request->user());
        $recipient = $this->queries->recipient($provider, $serviceRequest);
        Gate::authorize('viewAsProvider', $recipient->serviceRequest);
        $this->lifecycle->markSeen($recipient);

        return $this->success((new ProviderRequestResource($recipient))->detailed());
    }
}
