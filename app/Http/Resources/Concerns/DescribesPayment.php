<?php

namespace App\Http\Resources\Concerns;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;

/**
 * Payment fields shared by the client and the dashboard (slice-10-contract.md §10). Uses FormatsApiValues.
 * `refund.refunded_amount` is the money that actually went back (settled refunds).
 */
trait DescribesPayment
{
    protected function paymentCore(Payment $p): array
    {
        $refundState = $p->refundState();

        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'amount' => $this->money($p->amount),
            'method' => $p->method->value,
            'method_label' => $p->method->label(),
            'card' => $p->card ? ['brand' => $p->card['brand'], 'last4' => $p->card['last4']] : null,
            'failure_code' => $p->status === PaymentStatus::Failed ? $p->failure_code : null,
            'failure_message' => $p->status === PaymentStatus::Failed && $p->failure_code
                ? __('payments.failure.'.$p->failure_code) : null,
            'next_action' => $p->status === PaymentStatus::RequiresAction && $p->next_action_url
                ? ['type' => 'redirect', 'url' => $p->next_action_url] : null,
            'fulfilment' => $p->fulfilment_status->value,
            'fulfilment_error' => $p->fulfilment_error,
            'refund' => [
                'status' => $refundState->value,
                'status_label' => $refundState->label(),
                'refunded_amount' => $this->money(Money::fromCents($p->settledRefundCents())),
            ],
            'payable' => ['type' => $p->payable_type->value, 'id' => $p->payable_id],
            'booking' => $p->relationLoaded('booking') && $p->booking ? ['id' => $p->booking->id, 'reference' => $p->booking->reference] : null,
            'created_at' => $this->dateTime($p->created_at),
            'paid_at' => $this->dateTime($p->paid_at),
        ];
    }
}
