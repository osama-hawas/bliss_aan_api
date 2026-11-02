<?php

namespace App\Http\Controllers\Api\V1\Auth\Session;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\DeviceSessionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RefreshTokenController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DeviceSessionService $sessions) {}

    /**
     * Send the current (possibly expired) token as the bearer token.
     */
    public function __invoke(Request $request)
    {
        $token = $request->bearerToken() ?? throw new ApiException(ErrorCode::Unauthenticated);

        return $this->success($this->sessions->refresh($token));
    }
}
