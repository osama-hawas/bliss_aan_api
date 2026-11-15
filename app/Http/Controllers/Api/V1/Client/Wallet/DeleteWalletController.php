<?php

namespace App\Http\Controllers\Api\V1\Client\Wallet;

use App\Http\Controllers\Controller;
use App\Services\Wallets\WalletService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Closes her wallet; it is kept as history and she may create a new one (D106).
 */
class DeleteWalletController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WalletService $wallets) {}

    public function __invoke(Request $request)
    {
        $this->wallets->delete($request->user());

        return $this->success(null, __('Wallet deleted.'));
    }
}
