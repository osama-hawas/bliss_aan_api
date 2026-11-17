<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ContentReasonRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminCityResource;
use App\Services\Settings\CityManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: hides the city from pickers; nothing stored changes (D308).
 */
class StopCityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CityManagement $cities) {}

    public function __invoke(ContentReasonRequest $request, int $city)
    {
        $this->cities->stop($this->cities->find($city), $request->user(), $request->validated('reason'));

        return $this->success(new AdminCityResource($this->cities->find($city)), __('content.messages.stopped'));
    }
}
