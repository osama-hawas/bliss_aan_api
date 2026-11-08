<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\SetAvatarRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProfileResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class SetAvatarController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(SetAvatarRequest $request)
    {
        $mediaId = $request->validated('media_id');
        $provider = $this->setup->setAvatar($this->setup->activeProvider($request->user()), $request->user(), $mediaId === null ? null : (int) $mediaId);

        return $this->success(new ProfileResource(ProfileResource::load($provider)), __('Profile saved.'));
    }
}
