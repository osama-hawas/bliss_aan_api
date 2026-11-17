<?php

namespace App\Http\Controllers\Api\V1\Shared\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Account\DeletionRequestResource;
use App\Services\Account\AccountDeletion;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * POST shared/account/deletion-request/withdraw — her pending request → withdrawn (none pending → 409 INVALID_STATE).
 */
class WithdrawDeletionRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountDeletion $deletion) {}

    public function __invoke(Request $request)
    {
        return $this->success(new DeletionRequestResource($this->deletion->withdraw($request->user())), __('account.deletion_withdrawn'));
    }
}
