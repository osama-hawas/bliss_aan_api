<?php

namespace App\Http\Controllers\Api\V1\Client\Addresses;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Addresses\AddressResource;
use App\Services\ServiceRequests\AddressBook;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ListAddressesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AddressBook $addresses) {}

    public function __invoke(Request $request)
    {
        return $this->success(AddressResource::collection($this->addresses->list($request->user())));
    }
}
