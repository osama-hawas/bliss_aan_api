<?php

namespace App\Http\Controllers\Api\V1\Shared\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Account\UpdateProfileRequest;
use App\Http\Resources\Api\V1\Auth\Session\MeResource;
use App\Services\Account\ProfileService;
use App\Traits\ApiResponse;

/**
 * PATCH shared/account/profile — her own details (D152). Returns the same shape as `auth/me`.
 */
class UpdateProfileController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProfileService $profiles) {}

    public function __invoke(UpdateProfileRequest $request)
    {
        $user = $this->profiles->update($request->user(), $request->validated());

        return $this->success(new MeResource($user->load('city')), __('account.profile_updated'));
    }
}
