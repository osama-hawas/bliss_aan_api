<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Providers\AdminProviderDetailResource;
use App\Services\Providers\ProviderRoster;
use App\Traits\ApiResponse;

class ShowProviderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderRoster $roster) {}

    public function __invoke(int $provider)
    {
        return $this->success(new AdminProviderDetailResource(AdminProviderDetailResource::load($this->roster->find($provider))));
    }
}
