<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\OptionalReasonRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminCityResource;
use App\Services\Settings\CityManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: offers the city again.
 */
class ActivateCityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CityManagement $cities) {}

    public function __invoke(OptionalReasonRequest $request, int $city)
    {
        $this->cities->activate($this->cities->find($city), $request->user(), $request->validated('reason'));

        return $this->success(new AdminCityResource($this->cities->find($city)), __('content.messages.activated'));
    }
}
