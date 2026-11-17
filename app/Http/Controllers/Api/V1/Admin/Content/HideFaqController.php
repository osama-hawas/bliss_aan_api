<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ContentReasonRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminFaqResource;
use App\Services\Content\FaqManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: hides a faq, with a reason. Nothing is deleted (D301).
 */
class HideFaqController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FaqManagement $faqs) {}

    public function __invoke(ContentReasonRequest $request, int $faq)
    {
        $this->faqs->hide($this->faqs->find($faq), $request->user(), $request->validated('reason'));

        return $this->success(new AdminFaqResource($this->faqs->find($faq)), __('content.messages.hidden'));
    }
}
