<?php

namespace App\Http\Controllers\Api\V1\Auth\Register;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\Register\RegisterRequest;
use App\Http\Resources\Api\V1\Auth\Session\SessionResource;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;

class RegisterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $auth) {}

    public function __invoke(RegisterRequest $request)
    {
        $session = $this->auth->register(
            $request->validated('registration_token'),
            $request->profile(),
            $request->deviceApp(),
            $request->deviceInfo(),
        );

        return $this->success(new SessionResource($session), __('Account created.'), 201);
    }
}
