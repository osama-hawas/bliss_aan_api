<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\PageRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminPageResource;
use App\Services\Content\PageManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: creates a hidden page (D301).
 */
class CreatePageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PageManagement $pages) {}

    public function __invoke(PageRequest $request)
    {
        $row = $this->pages->create($request->user(), $request->page());

        return $this->success(new AdminPageResource($this->pages->find($row->id)), __('content.messages.created'), 201);
    }
}
