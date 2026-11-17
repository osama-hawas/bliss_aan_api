<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Enums\StoreReturnStatus;
use App\Http\Resources\Concerns\DescribesStoreReturn;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\ActivityEvent;
use App\Models\StoreReturn;
use App\Services\Store\Returns\StoreReturnProcessing;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * A store return for staff (S23, §6.2): everything the client sees plus her contact, the order and payment links, every
 * refund attempt, the full timeline with actors and internal notes, and the actions available now.
 *
 * @mixin StoreReturn
 */
class AdminStoreReturnResource extends AdminStoreReturnRowResource
{
    use DescribesStoreReturn, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        /** @var StoreReturn $return */
        $return = $this->resource;
        $order = $return->order;
        $payment = $order?->payment;
        $refunds = app(StoreReturnProcessing::class)->refundsOf($return);

        return [
            ...parent::toArray($request),
            ...$this->returnBasics($return),
            'reject_reason' => $return->reject_reason,
            'order' => $order === null ? null : [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'subtotal' => $this->money($order->subtotal),
                'discount' => $this->money($order->discount),
                'shipping_fee' => $this->money($order->shipping_fee),
                'total' => $this->money($order->total),
                'delivered_at' => $this->dateTime($order->delivered_at),
            ],
            'payment' => $payment === null ? null : [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => $this->money($payment->amount),
                'refundable_amount' => $this->money(Money::fromCents($payment->refundableCents())),
            ],
            'items' => $this->returnItems($return),
            'photos' => $this->returnPhotos($return),
            'refund' => $this->returnRefund($return),
            'refund_attempts' => $refunds->map(fn ($refund) => [
                'id' => $refund->id,
                'reference' => $refund->reference,
                'status' => self::returnEnum($refund->status),
                'status_label' => $refund->status->label(),
                'amount' => $this->money($refund->amount),
                'failure_code' => $refund->failure_code,
                'created_at' => $this->dateTime($refund->created_at),
            ])->values(),
            'restocked' => $return->restocked,
            'policy_version' => $return->policyVersion ? ['id' => $return->policyVersion->id, 'version' => $return->policyVersion->version] : null,
            'timeline' => $return->activity->sortBy('id')->map(fn (ActivityEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'event_label' => __('activity.'.$event->event),
                'status' => $event->to_status,
                'status_label' => $event->to_status ? StoreReturnStatus::tryFrom($event->to_status)?->label() : null,
                'note' => $event->meta['note'] ?? $event->meta['reason'] ?? null,
                'visibility' => self::returnEnum($event->visibility),
                'actor' => $event->actor ? ['id' => $event->actor->id, 'name' => $event->actor->name] : null,
                'at' => $this->dateTime($event->created_at),
            ])->values(),
            'next_actions' => $this->nextActions($return, $refunds),
        ];
    }

    /** @return list<string> */
    private function nextActions(StoreReturn $return, $refunds): array
    {
        return match ($return->status) {
            StoreReturnStatus::Requested => ['approve', 'reject'],
            StoreReturnStatus::Approved => ['receive'],
            StoreReturnStatus::Received => $refunds->contains(fn ($r) => self::returnEnum($r->status) !== 'failed') ? [] : ['retry_refund'],
            default => [],
        };
    }
}
