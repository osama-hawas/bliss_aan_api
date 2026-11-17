<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Providers\AdminProviderDetailResource;
use App\Services\Providers\ProviderReview;
use App\Services\Providers\ProviderRoster;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `providers.review` (D176).
 */
class RestorePortfolioItemController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderRoster $roster, private readonly ProviderReview $review) {}

    public function __invoke(Request $request, int $provider, int $item)
    {
        $this->review->restorePortfolioItem($this->roster->find($provider), $item, $request->user());

        return $this->success(new AdminProviderDetailResource(AdminProviderDetailResource::load($this->roster->find($provider))), __('accounts.messages.portfolio_restored'));
    }
}
