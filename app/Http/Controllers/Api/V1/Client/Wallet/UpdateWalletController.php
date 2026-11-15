<?php

namespace App\Http\Controllers\Api\V1\Client\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Wallet\WalletRequest;
use App\Http\Resources\Api\V1\Shared\Wallet\WalletResource;
use App\Services\Wallets\WalletService;
use App\Traits\ApiResponse;

/**
 * Updates the label of her wallet (the only editable information).
 */
class UpdateWalletController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WalletService $wallets) {}

    public function __invoke(WalletRequest $request)
    {
        return $this->success(new WalletResource($this->wallets->update($request->user(), $request->validated('label'))), __('Wallet updated.'));
    }
}
