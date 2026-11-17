<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Content\AdminPageResource;
use App\Services\Content\PageManagement;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `content.manage`: makes a page visible in the app; both languages required (D301).
 */
class PublishPageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PageManagement $pages) {}

    public function __invoke(Request $request, int $page)
    {
        $this->pages->show($this->pages->find($page), $request->user());

        return $this->success(new AdminPageResource($this->pages->find($page)), __('content.messages.shown'));
    }
}
