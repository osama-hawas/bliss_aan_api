<?php

namespace App\Http\Resources\Concerns;

use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Services\Payouts\Cents;
use App\Support\SaudiIban;

/**
 * Shared Slice 11 shapes (slice-11-contract.md §12). Cents become money strings; the IBAN is always masked here.
 */
trait DescribesPayouts
{
    use FormatsApiValues;

    protected function cents(?int $cents): ?array
    {
        return $cents === null ? null : $this->money(Cents::toDecimal($cents));
    }

    protected function payoutAccount(?PayoutAccount $account): ?array
    {
        return $account ? [
            'holder_name' => $account->holder_name,
            'iban_masked' => SaudiIban::mask($account->iban_last4),
            'iban_last4' => $account->iban_last4,
            'bank_name' => SaudiIban::bankName($account->bank_name_key),
            'created_at' => $this->dateTime($account->created_at),
            'updated_at' => $this->dateTime($account->updated_at),
        ] : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payoutRequestBase(PayoutRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'amount' => $this->cents($request->amount_cents),
        ];
    }

    protected function providerPayoutRequest(?PayoutRequest $request): ?array
    {
        return $request ? [
            ...$this->payoutRequestBase($request),
            'holder_name' => $request->holder_name,
            'iban_masked' => SaudiIban::mask($request->iban_last4),
            'bank_name' => SaudiIban::bankName($request->bank_name_key),
            'transfer_reference' => $request->transfer_reference,
            'rejection_reason' => $request->rejection_reason,
            'processed_at' => $this->dateTime($request->processed_at),
            'created_at' => $this->dateTime($request->created_at),
        ] : null;
    }
}
