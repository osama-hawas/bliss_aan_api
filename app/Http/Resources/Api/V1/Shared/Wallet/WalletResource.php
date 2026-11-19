<?php

namespace App\Http\Resources\Api\V1\Shared\Wallet;

use App\Enums\UserType;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Wallet;
use App\Services\Wallets\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account's own wallet (client or provider; same shape). A client's balance is spendable on bookings
 * (owner decision 2026-11-19); a provider's stays informational.
 *
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $w = $this->resource;

        return [
            'id' => $w->id,
            'owner_type' => $w->owner_type->value,
            'label' => $w->label,
            'currency' => $w->currency,
            'status' => $w->status->value,
            'status_label' => $w->status->label(),
            'balance' => [
                ...$this->money(app(WalletService::class)->balance($w)),
                // Owner decision 2026-11-19: a client's balance pays bookings; a provider's wallet stays informational
                // (her earnings live in the provider ledger).
                'is_spendable' => $w->owner_type === UserType::Client,
                'note' => __($w->owner_type === UserType::Client ? 'wallet.balance_note_client' : 'wallet.balance_note'),
            ],
            'created_at' => $this->dateTime($w->created_at),
            'updated_at' => $this->dateTime($w->updated_at),
        ];
    }
}
