<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\CityManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: what stopping the city touches (D308).
 */
class CityImpactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CityManagement $cities) {}

    public function __invoke(int $city)
    {
        return $this->success($this->cities->impact($this->cities->find($city)));
    }
}
