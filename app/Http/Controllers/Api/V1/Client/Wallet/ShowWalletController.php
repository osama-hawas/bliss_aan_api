<?php

namespace App\Http\Controllers\Api\V1\Client\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Wallet\WalletResource;
use App\Services\Wallets\WalletService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Her own wallet, or `data: null` when she has none yet (D106).
 */
class ShowWalletController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WalletService $wallets) {}

    public function __invoke(Request $request)
    {
        $wallet = $this->wallets->current($request->user());

        return $this->success($wallet ? new WalletResource($wallet) : null);
    }
}
