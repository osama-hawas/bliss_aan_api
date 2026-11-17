<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\PageRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminPageResource;
use App\Services\Content\PageManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: edits a page; a visible one keeps both languages (D301).
 */
class UpdatePageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PageManagement $pages) {}

    public function __invoke(PageRequest $request, int $page)
    {
        $this->pages->update($this->pages->find($page), $request->user(), $request->page());

        return $this->success(new AdminPageResource($this->pages->find($page)), __('content.messages.updated'));
    }
}
