<?php

namespace App\Http\Controllers\Api\V1\Client\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Account\SetAvatarRequest;
use App\Http\Resources\Api\V1\Auth\Session\MeResource;
use App\Services\Account\ProfileService;
use App\Traits\ApiResponse;

/**
 * PUT client/account/avatar — sets or (null) removes her profile photo (D153).
 */
class SetAvatarController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProfileService $profiles) {}

    public function __invoke(SetAvatarRequest $request)
    {
        $mediaId = $request->validated('media_id');
        $user = $this->profiles->setAvatar($request->user(), $mediaId === null ? null : (int) $mediaId);

        return $this->success(new MeResource($user->load('city')), __('account.avatar_updated'));
    }
}
