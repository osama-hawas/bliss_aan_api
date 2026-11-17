<?php

namespace App\Http\Controllers\Api\V1\Provider\Payouts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Payouts\ListLedgerEntriesRequest;
use App\Http\Resources\Api\V1\Provider\Payouts\LedgerEntryResource;
use App\Services\Payouts\ProviderEarnings;
use App\Services\Providers\ProviderOnboarding;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her ledger entries, newest first.
 */
class ListLedgerEntriesController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderOnboarding $providers,
        private readonly ProviderEarnings $earnings,
    ) {}

    public function __invoke(ListLedgerEntriesRequest $request)
    {
        $page = $this->earnings->entries($this->providers->providerOf($request->user()), $request->validated('filter.type'), Pagination::perPage($request));

        return $this->success(LedgerEntryResource::collection($page));
    }
}
