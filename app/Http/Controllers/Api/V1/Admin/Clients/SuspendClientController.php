<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\ReasonRequest;
use App\Http\Resources\Api\V1\Admin\Clients\AdminClientDetailResource;
use App\Services\Accounts\ClientAccounts;
use App\Traits\ApiResponse;

/**
 * `clients.suspend` (D171, D173): the reason is shown to her at login; every session is revoked.
 */
class SuspendClientController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClientAccounts $clients) {}

    public function __invoke(ReasonRequest $request, int $client)
    {
        $this->clients->suspend($this->clients->find($client), $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminClientDetailResource($this->clients->find($client)), __('accounts.messages.client_suspended'));
    }
}
