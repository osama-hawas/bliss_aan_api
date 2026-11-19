<?php

namespace App\Http\Controllers\Api\V1\Client\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Wallet\TopUpWalletRequest;
use App\Http\Resources\Api\V1\Shared\Wallet\WalletResource;
use App\Services\Wallets\WalletService;
use App\Services\Wallets\WalletTopUps;
use App\Traits\ApiResponse;

/**
 * `POST client/wallet/top-ups` {amount}: adds to her balance (directly until a gateway is connected) and returns her
 * wallet with the new balance.
 */
class TopUpWalletController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WalletTopUps $topUps, private readonly WalletService $wallets) {}

    public function __invoke(TopUpWalletRequest $request)
    {
        $this->topUps->topUp($request->user(), (string) $request->validated('amount'), $request->validated('idempotency_key'));

        return $this->success(new WalletResource($this->wallets->current($request->user())), __('wallet.topped_up'));
    }
}
