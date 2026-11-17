<?php

namespace App\Http\Controllers\Api\V1\Admin\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Clients\ProcessDeletionRequestRequest;
use App\Http\Resources\Api\V1\Admin\Clients\AdminDeletionRequestResource;
use App\Services\Account\AccountDeletionProcessor;
use App\Services\Account\AccountDeletionQueries;
use App\Traits\ApiResponse;

/**
 * `clients.edit` (S22, D336): process now, ignoring the grace period. 409 DELETION_BLOCKED (with `errors.obligations`)
 * while obligations remain; 409 INVALID_STATE once the request is no longer pending.
 */
class ProcessDeletionRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccountDeletionProcessor $processor,
        private readonly AccountDeletionQueries $queries,
    ) {}

    public function __invoke(ProcessDeletionRequestRequest $request, int $deletionRequest)
    {
        $this->queries->find($deletionRequest); // 404 first
        $this->processor->processNow($deletionRequest, $request->user(), $request->validated('reason'));

        return $this->success(new AdminDeletionRequestResource($this->queries->find($deletionRequest)), __('account.deletion_processed'));
    }
}
