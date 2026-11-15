<?php

namespace App\Http\Resources\Api\V1\Shared\Wallet;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Wallet;
use App\Services\Wallets\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account's own wallet (client or provider; same shape). The balance is informational only (D106):
 * `is_spendable` is always false and the apps must never offer to pay with it.
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
                'is_spendable' => false,
                'note' => __('wallet.balance_note'),
            ],
            'created_at' => $this->dateTime($w->created_at),
            'updated_at' => $this->dateTime($w->updated_at),
        ];
    }
}
