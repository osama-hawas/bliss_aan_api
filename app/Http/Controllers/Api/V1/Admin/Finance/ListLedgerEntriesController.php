<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Finance\ListLedgerEntriesRequest;
use App\Http\Resources\Api\V1\Admin\Finance\AdminLedgerEntryResource;
use App\Services\Finance\LedgerEntryQueries;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Provider ledger entries across providers (finance.view).
 */
class ListLedgerEntriesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly LedgerEntryQueries $entries) {}

    public function __invoke(ListLedgerEntriesRequest $request)
    {
        return $this->success(AdminLedgerEntryResource::collection(
            $this->entries->list($request->filters(), $request->validated('sort'), Pagination::perPage($request)),
        ));
    }
}
