<?php

namespace App\Http\Controllers\Api\V1\Shared\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Account\StoreDeletionRequestRequest;
use App\Http\Resources\Api\V1\Shared\Account\DeletionRequestResource;
use App\Services\Account\AccountDeletion;
use App\Traits\ApiResponse;

/**
 * POST shared/account/deletion-request — one pending request per account (409 DELETION_PENDING).
 */
class StoreDeletionRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountDeletion $deletion) {}

    public function __invoke(StoreDeletionRequestRequest $request)
    {
        $created = $this->deletion->request($request->user(), $request->reason(), $request->validated('details'));

        return $this->success(new DeletionRequestResource($created), __('account.deletion_requested'), 201);
    }
}
