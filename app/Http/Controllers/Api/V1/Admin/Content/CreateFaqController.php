<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\FaqRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminFaqResource;
use App\Services\Content\FaqManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: creates a hidden faq (D301).
 */
class CreateFaqController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FaqManagement $faqs) {}

    public function __invoke(FaqRequest $request)
    {
        $row = $this->faqs->create($request->user(), $request->faq());

        return $this->success(new AdminFaqResource($this->faqs->find($row->id)), __('content.messages.created'), 201);
    }
}
