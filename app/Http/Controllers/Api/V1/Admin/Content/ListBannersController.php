<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ListBannersRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerResource;
use App\Services\Content\BannerManagement;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `content.manage`: banners with search, status and sort (Slice 20).
 */
class ListBannersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BannerManagement $banners) {}

    public function __invoke(ListBannersRequest $request)
    {
        return $this->success(AdminBannerResource::collection($this->banners->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
