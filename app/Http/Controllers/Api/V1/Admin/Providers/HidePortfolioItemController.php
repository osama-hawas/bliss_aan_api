<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\ReasonRequest;
use App\Http\Resources\Api\V1\Admin\Providers\AdminProviderDetailResource;
use App\Services\Providers\ProviderReview;
use App\Services\Providers\ProviderRoster;
use App\Traits\ApiResponse;

/**
 * `providers.review` (D176): the item disappears from every public list; the reason is kept.
 */
class HidePortfolioItemController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderRoster $roster, private readonly ProviderReview $review) {}

    public function __invoke(ReasonRequest $request, int $provider, int $item)
    {
        $this->review->hidePortfolioItem($this->roster->find($provider), $item, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminProviderDetailResource(AdminProviderDetailResource::load($this->roster->find($provider))), __('accounts.messages.portfolio_hidden'));
    }
}
