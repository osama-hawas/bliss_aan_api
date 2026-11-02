<?php

namespace App\Http\Controllers\Api\V1\Auth\Otp;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\Otp\VerifyOtpRequest;
use App\Http\Resources\Api\V1\Auth\Session\SessionResource;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;

class VerifyOtpController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * `login` returns a session. `register` returns a one-time `registration_token`
     * for `POST auth/register`.
     */
    public function __invoke(VerifyOtpRequest $request)
    {
        $phone = $request->validated('phone');
        $code = $request->validated('code');

        if ($request->purpose() === OtpPurpose::Register) {
            return $this->success($this->auth->verifyRegistration($phone, $code, $request->deviceApp()));
        }

        $session = $this->auth->login($phone, $code, $request->deviceApp(), $request->deviceInfo());

        return $this->success(new SessionResource($session), __('Logged in.'));
    }
}
