<?php

namespace App\Http\Controllers\Api\V1\Client\Offers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Offers\ListOffersRequest;
use App\Http\Resources\Api\V1\Client\Offers\ClientOfferResource;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Offers on her own request, for the list and comparison (Req 19). Never superseded or withdrawn offers.
 */
class ListRequestOffersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries) {}

    public function __invoke(ListOffersRequest $request, int $serviceRequest)
    {
        $model = $this->queries->clientRequest($request->user(), $serviceRequest);
        Gate::authorize('viewAsClient', $model);

        return $this->success(ClientOfferResource::collection($this->queries->clientOffers($model, $request->validated('sort'))));
    }
}
