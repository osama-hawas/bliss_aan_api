<?php

namespace App\Http\Controllers\Api\V1\Auth\Otp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\Otp\RequestOtpRequest;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;

class RequestOtpController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $auth) {}

    public function __invoke(RequestOtpRequest $request)
    {
        $result = $this->auth->requestOtp($request->validated('phone'), $request->purpose(), $request->deviceApp());

        return $this->success($result, __('Verification code sent.'));
    }
}
