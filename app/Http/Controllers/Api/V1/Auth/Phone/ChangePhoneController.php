<?php

namespace App\Http\Controllers\Api\V1\Auth\Phone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\Phone\ChangePhoneRequest;
use App\Http\Resources\Api\V1\Auth\Session\MeResource;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;

class ChangePhoneController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $auth) {}

    public function __invoke(ChangePhoneRequest $request)
    {
        $user = $this->auth->changePhone($request->user(), $request->validated('phone'), $request->validated('code'));

        return $this->success(new MeResource($user->load('city')), __('Mobile number changed.'));
    }
}
