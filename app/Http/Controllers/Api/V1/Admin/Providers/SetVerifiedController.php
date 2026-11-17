<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\SetProviderFlagRequest;
use App\Http\Resources\Api\V1\Admin\Providers\AdminProviderDetailResource;
use App\Services\Providers\ProviderReview;
use App\Services\Providers\ProviderRoster;
use App\Traits\ApiResponse;

/**
 * `providers.verify` (D175): `POST admin/providers/{id}/verification`.
 */
class SetVerifiedController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderRoster $roster, private readonly ProviderReview $review) {}

    public function __invoke(SetProviderFlagRequest $request, int $provider)
    {
        $this->review->setVerified($this->roster->find($provider), $request->user(), $request->boolean('value'), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminProviderDetailResource(AdminProviderDetailResource::load($this->roster->find($provider))), __('accounts.messages.provider_saved'));
    }
}
