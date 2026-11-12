<?php

namespace App\Http\Controllers\Api\V1\Client\Addresses;

use App\Http\Controllers\Controller;
use App\Services\ServiceRequests\AddressBook;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeleteAddressController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AddressBook $addresses) {}

    public function __invoke(Request $request, int $address)
    {
        $model = $this->addresses->find($request->user(), $address);
        Gate::authorize('delete', $model);
        $this->addresses->delete($model);

        return $this->success(null, __('Address deleted.'));
    }
}
