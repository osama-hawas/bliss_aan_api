<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Clients\UpdateClientRequest;
use App\Http\Resources\Api\V1\Admin\Clients\AdminClientDetailResource;
use App\Services\Accounts\ClientAccounts;
use App\Traits\ApiResponse;

/**
 * `clients.edit` (D174): name, email, city, with a reason.
 */
class UpdateClientController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClientAccounts $clients) {}

    public function __invoke(UpdateClientRequest $request, int $client)
    {
        $this->clients->update($this->clients->find($client), $request->user(), $request->profile(), $request->validated('reason'));

        return $this->success(new AdminClientDetailResource($this->clients->find($client)), __('accounts.messages.client_updated'));
    }
}
