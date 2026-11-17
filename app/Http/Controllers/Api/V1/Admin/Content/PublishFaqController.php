<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Content\AdminFaqResource;
use App\Services\Content\FaqManagement;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `content.manage`: makes a faq visible in the app; both languages required (D301).
 */
class PublishFaqController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FaqManagement $faqs) {}

    public function __invoke(Request $request, int $faq)
    {
        $this->faqs->show($this->faqs->find($faq), $request->user());

        return $this->success(new AdminFaqResource($this->faqs->find($faq)), __('content.messages.shown'));
    }
}
