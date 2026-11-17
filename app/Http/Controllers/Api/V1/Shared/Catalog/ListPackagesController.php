<?php

namespace App\Http\Controllers\Api\V1\Shared\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Catalog\ListPackagesRequest;
use App\Http\Resources\Api\V1\Shared\Catalog\PackageCardResource;
use App\Services\Catalog\PackageBrowser;
use App\Traits\ApiResponse;

/**
 * Visible packages (Slice 24 §5), optionally for one event type (`filter[event_type_id]`; packages with no event type
 * are always included, D351). Public, not paginated (an admin-curated list).
 */
class ListPackagesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PackageBrowser $packages) {}

    public function __invoke(ListPackagesRequest $request)
    {
        return $this->success(PackageCardResource::collection($this->packages->list($request->eventTypeId())));
    }
}
