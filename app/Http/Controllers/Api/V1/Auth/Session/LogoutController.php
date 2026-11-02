<?php

namespace App\Http\Controllers\Api\V1\Auth\Session;

use App\Http\Controllers\Controller;
use App\Services\Auth\DeviceSessionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DeviceSessionService $sessions) {}

    public function __invoke(Request $request)
    {
        $this->sessions->logout($request->attributes->get('device'));

        return $this->success(null, __('Logged out.'));
    }
}
