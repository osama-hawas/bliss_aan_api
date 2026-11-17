<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Clients\AdminClientDetailResource;
use App\Services\Accounts\ClientAccounts;
use App\Traits\ApiResponse;

class ShowClientController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClientAccounts $clients) {}

    public function __invoke(int $client)
    {
        return $this->success(new AdminClientDetailResource($this->clients->find($client)));
    }
}
