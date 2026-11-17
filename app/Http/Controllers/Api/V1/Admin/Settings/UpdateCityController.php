<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\CityRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminCityResource;
use App\Services\Settings\CityManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: renames or moves a city.
 */
class UpdateCityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CityManagement $cities) {}

    public function __invoke(CityRequest $request, int $city)
    {
        $this->cities->update($this->cities->find($city), $request->user(), $request->city());

        return $this->success(new AdminCityResource($this->cities->find($city)), __('content.messages.updated'));
    }
}
