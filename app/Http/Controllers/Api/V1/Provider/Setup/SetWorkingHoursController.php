<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\WorkingHoursRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProfileResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class SetWorkingHoursController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(WorkingHoursRequest $request)
    {
        $hours = array_map(fn (array $h) => ['weekday' => (int) $h['weekday'], 'opens_at' => $h['opens_at'], 'closes_at' => $h['closes_at']], $request->validated('hours'));
        $provider = $this->setup->setWorkingHours($this->setup->activeProvider($request->user()), $hours);

        return $this->success(new ProfileResource(ProfileResource::load($provider)), __('Working hours saved.'));
    }
}
