<?php

namespace App\Http\Controllers\Api\V1\Shared\Devices;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Devices\DeviceResource;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ListDevicesController extends Controller
{
    use ApiResponse;

    /**
     * Open sessions of the signed-in user, most recently active first.
     */
    public function __invoke(Request $request)
    {
        $devices = $request->user()->devices()->whereNull('revoked_at')->latest('last_seen_at')->latest('id')->get();

        return $this->success(DeviceResource::collection($devices));
    }
}
