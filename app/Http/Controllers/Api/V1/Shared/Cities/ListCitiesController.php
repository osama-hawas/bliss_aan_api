<?php

namespace App\Http\Controllers\Api\V1\Shared\Cities;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Cities\CityResource;
use App\Models\City;
use App\Traits\ApiResponse;

class ListCitiesController extends Controller
{
    use ApiResponse;

    /**
     * Active cities for pickers; public because sign-up needs it before there is a session.
     */
    public function __invoke()
    {
        $cities = City::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        return $this->success(CityResource::collection($cities));
    }
}
