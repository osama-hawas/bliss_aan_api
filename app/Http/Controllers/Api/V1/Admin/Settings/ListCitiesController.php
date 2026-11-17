<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\ListCitiesRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminCityResource;
use App\Services\Settings\CityManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: every city with its coverage figures (D308).
 */
class ListCitiesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CityManagement $cities) {}

    public function __invoke(ListCitiesRequest $request)
    {
        return $this->success(AdminCityResource::collection($this->cities->list($request->validated('q'), $request->validated('filter.status'))));
    }
}
