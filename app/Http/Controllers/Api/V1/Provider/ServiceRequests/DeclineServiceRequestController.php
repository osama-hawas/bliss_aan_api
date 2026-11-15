<?php

namespace App\Http\Controllers\Api\V1\Provider\ServiceRequests;

use App\Enums\RecipientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\ServiceRequests\DeclineServiceRequestRequest;
use App\Http\Resources\Api\V1\Provider\ServiceRequests\ProviderRequestResource;
use App\Models\DeclineReason;
use App\Services\Providers\ProviderSetup;
use App\Services\ServiceRequests\ServiceRequestLifecycle;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

class DeclineServiceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly ServiceRequestQueries $queries,
        private readonly ServiceRequestLifecycle $lifecycle,
    ) {}

    public function __invoke(DeclineServiceRequestRequest $request, int $serviceRequest)
    {
        $provider = $this->setup->activeProvider($request->user());
        $recipient = $this->queries->recipient($provider, $serviceRequest);
        Gate::authorize('decline', $recipient->serviceRequest);
        $reason = DeclineReason::query()->active()->findOrFail((int) $request->validated('decline_reason_id'));
        $withdraws = $recipient->status === RecipientStatus::OfferSent; // D91: withdraw her offer and decline
        $this->lifecycle->decline($provider, $recipient->serviceRequest, $reason, $request->validated('explanation'));

        return $this->success(
            (new ProviderRequestResource($this->queries->recipient($provider, $serviceRequest)))->detailed(),
            $withdraws ? __('Offer withdrawn and request declined.') : __('Request declined.'),
        );
    }
}
