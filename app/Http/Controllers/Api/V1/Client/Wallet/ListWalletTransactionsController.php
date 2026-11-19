<?php

namespace App\Http\Controllers\Api\V1\Client\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Client\Wallet\WalletTransactionResource;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `GET client/wallet/transactions`: every entry of her wallets (closed ones included), newest first, paged.
 */
class ListWalletTransactionsController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request)
    {
        $walletIds = Wallet::query()->where('user_id', $request->user()->id)->pluck('id');
        $page = WalletLedgerEntry::query()->whereIn('wallet_id', $walletIds)
            ->with('source')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 50));

        return $this->success(WalletTransactionResource::collection($page));
    }
}
