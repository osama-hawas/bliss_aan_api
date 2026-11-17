<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Content\AdminFaqResource;
use App\Services\Content\FaqManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: one faq.
 */
class ShowFaqController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FaqManagement $faqs) {}

    public function __invoke(int $faq)
    {
        return $this->success(new AdminFaqResource($this->faqs->find($faq)));
    }
}
