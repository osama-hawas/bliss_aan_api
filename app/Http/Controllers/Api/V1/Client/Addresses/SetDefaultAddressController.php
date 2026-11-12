<?php

namespace App\Http\Controllers\Api\V1\Client\Addresses;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Addresses\AddressResource;
use App\Services\ServiceRequests\AddressBook;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SetDefaultAddressController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AddressBook $addresses) {}

    public function __invoke(Request $request, int $address)
    {
        $model = $this->addresses->find($request->user(), $address);
        Gate::authorize('setDefault', $model);

        return $this->success(new AddressResource($this->addresses->setDefault($model)), __('Default address updated.'));
    }
}
