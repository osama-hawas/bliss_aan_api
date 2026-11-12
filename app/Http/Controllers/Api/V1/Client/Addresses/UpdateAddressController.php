<?php

namespace App\Http\Controllers\Api\V1\Client\Addresses;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Addresses\UpdateAddressRequest;
use App\Http\Resources\Api\V1\Client\Addresses\AddressResource;
use App\Services\ServiceRequests\AddressBook;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

class UpdateAddressController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AddressBook $addresses) {}

    public function __invoke(UpdateAddressRequest $request, int $address)
    {
        $model = $this->addresses->find($request->user(), $address);
        Gate::authorize('update', $model);

        return $this->success(new AddressResource($this->addresses->update($model, $request->addressData())), __('Address saved.'));
    }
}
