<?php

namespace App\Services\Content;

use App\Enums\Audience;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Faq;
use App\Models\Page;
use Illuminate\Support\Collection;

/**
 * Public FAQs and pages of one app (D158). Only active rows; editing is Slice 20.
 */
class PublicContent
{
    /**
     * @return Collection<int, Faq>
     */
    public function faqs(Audience $app): Collection
    {
        return Faq::query()->where('audience', $app)->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
    }

    public function page(Audience $app, string $slug): Page
    {
        return Page::query()->where('audience', $app)->where('slug', $slug)->where('is_active', true)->first()
            ?? throw new ApiException(ErrorCode::NotFound);
    }
}
