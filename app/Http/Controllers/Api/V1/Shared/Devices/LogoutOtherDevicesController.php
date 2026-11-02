<?php

namespace App\Http\Controllers\Api\V1\Shared\Devices;

use App\Http\Controllers\Controller;
use App\Services\Auth\DeviceSessionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class LogoutOtherDevicesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DeviceSessionService $sessions) {}

    public function __invoke(Request $request)
    {
        $count = $this->sessions->logoutOthers($request->attributes->get('device'));

        return $this->success(['revoked_count' => $count], __('Other devices logged out.'));
    }
}
