<?php

namespace App\Http\Controllers\Api\V1\Shared\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Content\ContentAppRequest;
use App\Http\Resources\Api\V1\Shared\Content\FaqResource;
use App\Services\Content\PublicContent;
use App\Traits\ApiResponse;

/**
 * Active FAQs of one app, by `sort` then id (D158). Public.
 */
class ListFaqsController extends Controller
{
    use ApiResponse;

    public function __invoke(ContentAppRequest $request, PublicContent $content)
    {
        return $this->success(FaqResource::collection($content->faqs($request->app())));
    }
}
