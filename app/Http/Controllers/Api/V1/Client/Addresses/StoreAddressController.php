<?php

namespace App\Http\Controllers\Api\V1\Client\Addresses;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Addresses\StoreAddressRequest;
use App\Http\Resources\Api\V1\Client\Addresses\AddressResource;
use App\Services\ServiceRequests\AddressBook;
use App\Traits\ApiResponse;

class StoreAddressController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AddressBook $addresses) {}

    public function __invoke(StoreAddressRequest $request)
    {
        $address = $this->addresses->create($request->user(), $request->addressData());

        return $this->success(new AddressResource($address), __('Address saved.'), 201);
    }
}
