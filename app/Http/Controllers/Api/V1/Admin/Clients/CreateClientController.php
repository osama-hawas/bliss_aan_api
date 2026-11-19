<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Accounts\CreateAccountRequest;
use App\Http\Resources\Api\V1\Admin\Clients\AdminClientDetailResource;
use App\Services\Accounts\AccountCreator;
use App\Services\Accounts\ClientAccounts;
use App\Traits\ApiResponse;

/**
 * `POST admin/clients` (`clients.edit`): a client account added from the dashboard; she signs in with OTP.
 */
class CreateClientController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccountCreator $creator,
        private readonly ClientAccounts $clients,
    ) {}

    public function __invoke(CreateAccountRequest $request)
    {
        $client = $this->creator->createClient($request->user(), $request->profile(), $request->validated('reason'));

        return $this->success(new AdminClientDetailResource($this->clients->find($client->id)), __('accounts.messages.client_created'), 201);
    }
}
