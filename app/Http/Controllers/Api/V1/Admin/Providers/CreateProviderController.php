<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Enums\ProviderAccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Accounts\CreateAccountRequest;
use App\Http\Resources\Api\V1\Admin\Providers\ApplicationDetailResource;
use App\Services\Accounts\AccountCreator;
use App\Traits\ApiResponse;

/**
 * `POST admin/provider-applications` (`providers.review`): a provider account added from the dashboard. It starts as a
 * draft application (D25); she signs in with OTP, completes it and submits it for review like any applicant.
 */
class CreateProviderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountCreator $creator) {}

    public function __invoke(CreateAccountRequest $request)
    {
        $type = $request->validated('account_type');
        $provider = $this->creator->createProvider(
            $request->user(),
            $request->profile(),
            $type !== null ? ProviderAccountType::from($type) : null,
            $request->validated('reason'),
        );

        return $this->success(new ApplicationDetailResource(ApplicationDetailResource::load($provider)), __('accounts.messages.provider_created'), 201);
    }
}
