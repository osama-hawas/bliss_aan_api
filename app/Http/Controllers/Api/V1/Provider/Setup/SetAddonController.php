<?php

namespace App\Http\Controllers\Api\V1\Provider\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Setup\SetAddonRequest;
use App\Http\Resources\Api\V1\Provider\Setup\ProviderAddonResource;
use App\Services\Providers\ProviderSetup;
use App\Traits\ApiResponse;

class SetAddonController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup) {}

    public function __invoke(SetAddonRequest $request, int $addon)
    {
        $priced = $this->setup->setAddon(
            $this->setup->activeProvider($request->user()),
            $addon,
            (string) $request->validated('price'),
            (bool) $request->validated('is_active'),
            array_map('intval', $request->validated('provider_service_ids')),
        );

        return $this->success(new ProviderAddonResource($priced), __('Add-on saved.'));
    }
}
