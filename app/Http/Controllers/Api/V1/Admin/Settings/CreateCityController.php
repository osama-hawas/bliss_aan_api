<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\CityRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminCityResource;
use App\Services\Settings\CityManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: a new active city.
 */
class CreateCityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CityManagement $cities) {}

    public function __invoke(CityRequest $request)
    {
        $city = $this->cities->create($request->user(), $request->city());

        return $this->success(new AdminCityResource($this->cities->find($city->id)), __('content.messages.created'), 201);
    }
}
