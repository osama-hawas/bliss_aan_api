<?php

namespace App\Http\Controllers\Api\V1\Shared\Devices;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Devices\UpdateFcmTokenRequest;
use App\Http\Resources\Api\V1\Shared\Devices\DeviceResource;
use App\Services\Auth\DeviceSessionService;
use App\Traits\ApiResponse;

class UpdateFcmTokenController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DeviceSessionService $sessions) {}

    /**
     * Sets the push token of the device that sent the request.
     */
    public function __invoke(UpdateFcmTokenRequest $request)
    {
        $device = $this->sessions->setFcmToken($request->attributes->get('device'), $request->validated('fcm_token'));

        return $this->success(new DeviceResource($device), __('Saved.'));
    }
}
