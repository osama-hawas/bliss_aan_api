<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ListFaqsRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminFaqResource;
use App\Services\Content\FaqManagement;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * `content.manage`: faqs with search, status and sort (Slice 20).
 */
class ListFaqsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FaqManagement $faqs) {}

    public function __invoke(ListFaqsRequest $request)
    {
        return $this->success(AdminFaqResource::collection($this->faqs->list($request->filters(), $request->validated('sort'), Pagination::perPage($request))));
    }
}
