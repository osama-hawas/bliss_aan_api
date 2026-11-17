<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\FaqRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminFaqResource;
use App\Services\Content\FaqManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: edits a faq; a visible one keeps both languages (D301).
 */
class UpdateFaqController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FaqManagement $faqs) {}

    public function __invoke(FaqRequest $request, int $faq)
    {
        $this->faqs->update($this->faqs->find($faq), $request->user(), $request->faq());

        return $this->success(new AdminFaqResource($this->faqs->find($faq)), __('content.messages.updated'));
    }
}
