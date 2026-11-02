<?php

namespace App\Http\Controllers\Api\V1\Auth\Phone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\Phone\RequestPhoneChangeOtpRequest;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;

class RequestPhoneChangeOtpController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * Sends the code to the new number.
     */
    public function __invoke(RequestPhoneChangeOtpRequest $request)
    {
        $result = $this->auth->requestPhoneChange($request->user(), $request->validated('phone'));

        return $this->success($result, __('Verification code sent.'));
    }
}
