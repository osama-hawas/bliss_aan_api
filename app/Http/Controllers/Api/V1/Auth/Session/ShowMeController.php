<?php

namespace App\Http\Controllers\Api\V1\Auth\Session;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Auth\Session\MeResource;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShowMeController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request)
    {
        return $this->success(new MeResource($request->user()->load('city')));
    }
}
