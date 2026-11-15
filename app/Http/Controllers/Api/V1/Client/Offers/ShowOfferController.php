<?php

namespace App\Http\Controllers\Api\V1\Client\Offers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Offers\ClientOfferResource;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One offer on her own request; any other id (or a superseded/withdrawn offer) is a 404.
 */
class ShowOfferController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries) {}

    public function __invoke(Request $request, int $offer)
    {
        $model = $this->queries->clientOffer($request->user(), $offer);
        Gate::authorize('viewAsClient', $model->serviceRequest);

        return $this->success(new ClientOfferResource($model));
    }
}
