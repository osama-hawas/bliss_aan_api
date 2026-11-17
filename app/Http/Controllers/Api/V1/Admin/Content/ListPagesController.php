<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ListPagesRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminPageResource;
use App\Services\Content\PageManagement;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `content.manage`: pages with search, status and sort (Slice 20).
 */
class ListPagesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PageManagement $pages) {}

    public function __invoke(ListPagesRequest $request)
    {
        return $this->success(AdminPageResource::collection($this->pages->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
