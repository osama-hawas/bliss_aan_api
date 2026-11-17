<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Clients\AdminDeletionRequestResource;
use App\Services\Account\AccountDeletionQueries;
use App\Traits\ApiResponse;

class ShowDeletionRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountDeletionQueries $queries) {}

    public function __invoke(int $deletionRequest)
    {
        return $this->success(new AdminDeletionRequestResource($this->queries->find($deletionRequest)));
    }
}
