<?php

namespace App\Http\Resources\Api\V1\Client\Wallet;

use App\Enums\PayableType;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\WalletLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of her transaction history: top-up (+), booking payment (−) or refund (+), with what it was for.
 *
 * @mixin WalletLedgerEntry
 */
class WalletTransactionResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $source = $e->source;
        $payment = $source instanceof Refund ? $source->payment : ($source instanceof Payment ? $source : null);
        $booking = $payment?->payable_type === PayableType::Offer ? Booking::query()->where('offer_id', $payment->payable_id)->first(['id', 'reference']) : null;

        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => __('wallet.entry.'.$e->type),
            'direction' => str_starts_with((string) $e->amount, '-') ? 'out' : 'in',
            'amount' => $this->money(ltrim((string) $e->amount, '-')),
            'reference' => $source instanceof Refund ? $source->reference : $payment?->reference,
            'booking' => $booking ? ['id' => $booking->id, 'reference' => $booking->reference] : null,
            'created_at' => $this->dateTime($e->created_at),
        ];
    }
}
