<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ContentReasonRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminPageResource;
use App\Services\Content\PageManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: hides a page, with a reason. Nothing is deleted (D301).
 */
class HidePageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PageManagement $pages) {}

    public function __invoke(ContentReasonRequest $request, int $page)
    {
        $this->pages->hide($this->pages->find($page), $request->user(), $request->validated('reason'));

        return $this->success(new AdminPageResource($this->pages->find($page)), __('content.messages.hidden'));
    }
}
