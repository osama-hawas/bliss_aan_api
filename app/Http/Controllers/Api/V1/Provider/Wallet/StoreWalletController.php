<?php

namespace App\Http\Controllers\Api\V1\Provider\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Wallet\WalletRequest;
use App\Http\Resources\Api\V1\Shared\Wallet\WalletResource;
use App\Services\Wallets\WalletService;
use App\Traits\ApiResponse;

/**
 * Creates her wallet (one active wallet per account → 409 WALLET_EXISTS).
 */
class StoreWalletController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WalletService $wallets) {}

    public function __invoke(WalletRequest $request)
    {
        return $this->success(new WalletResource($this->wallets->create($request->user(), $request->validated('label'))), __('Wallet created.'), 201);
    }
}
