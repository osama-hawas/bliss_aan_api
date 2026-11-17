<?php

namespace App\Http\Controllers\Api\V1\Shared\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Content\ContentAppRequest;
use App\Http\Resources\Api\V1\Shared\Content\PageResource;
use App\Services\Content\PublicContent;
use App\Traits\ApiResponse;

/**
 * One active page of one app (terms, privacy…); unknown or inactive → 404 (D158). Public.
 */
class ShowPageController extends Controller
{
    use ApiResponse;

    public function __invoke(ContentAppRequest $request, PublicContent $content, string $slug)
    {
        return $this->success(new PageResource($content->page($request->app(), $slug)));
    }
}
