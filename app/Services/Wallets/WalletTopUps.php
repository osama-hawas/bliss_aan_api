<?php

namespace App\Services\Wallets;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\Settings\AppSettings;

/**
 * Adding money to her wallet (owner decision 2026-11-19). Until a payment gateway is connected the amount is added
 * directly (`wallet.direct_top_up` = true). Direct top-ups are never allowed in production: there they would be free
 * money. With the setting off, top-ups go through the gateway (not built yet → 409 NOT_CONFIGURED).
 */
class WalletTopUps
{
    public function __construct(private readonly WalletLedger $ledger, private readonly AppSettings $settings) {}

    public function topUp(User $client, string $amount, string $idempotencyKey): WalletLedgerEntry
    {
        $direct = (bool) $this->settings->get('wallet.direct_top_up', false);
        if (! $direct || app()->environment('production')) {
            throw new ApiException(ErrorCode::NotConfigured, __('wallet.top_up_gateway_missing'));
        }

        return $this->ledger->credit($client, $amount, WalletLedger::TOP_UP, null, ['mode' => 'direct'], 'top_up:'.$idempotencyKey);
    }
}
