<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Providers\ApplicationDetailResource;
use App\Models\Provider;
use App\Traits\ApiResponse;

class ShowApplicationController extends Controller
{
    use ApiResponse;

    public function __invoke(Provider $provider)
    {
        return $this->success(new ApplicationDetailResource(ApplicationDetailResource::load($provider)));
    }
}
