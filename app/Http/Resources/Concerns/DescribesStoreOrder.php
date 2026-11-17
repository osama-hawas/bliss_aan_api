<?php

namespace App\Http\Resources\Concerns;

use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\Store\StoreMoney;
use BackedEnum;

/**
 * Fields a store order shows to the client and to staff (Slice 9b, `slice-9b-contract.md` §6.4 / §8.2).
 * Needs FormatsApiValues and PublicMediaUrl on the resource.
 */
trait DescribesStoreOrder
{
    /** @return array<string, mixed> */
    protected function orderMoney(StoreOrder $order): array
    {
        return [
            'subtotal' => $this->money($order->subtotal),
            'discount' => $this->money($order->discount),
            'promo' => $order->promo,
            'shipping_fee' => $this->money($order->shipping_fee),
            'vat' => $order->vat_percent === null ? null : ['percent' => $order->vat_percent, 'amount' => $this->money($order->vat_amount)],
            'total' => $this->money($order->total),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function orderItems(StoreOrder $order): array
    {
        return $order->items->map(fn (StoreOrderItem $item) => [
            'variant_id' => $item->product_variant_id,
            'product_id' => $item->product_id,
            'name' => $item->localizedName(),
            'options' => $item->resolvedOptions(),
            'sku' => $item->sku,
            'image_url' => $this->publicUrl($item->variant?->product?->image),
            'unit_price' => $this->money($item->unit_price),
            'quantity' => $item->quantity,
            'line_total' => $this->money($item->line_total),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    protected function orderAddress(StoreOrder $order): array
    {
        $address = $order->address ?? [];
        $city = $address['city'] ?? null;

        return [
            'city_id' => $address['city_id'] ?? null,
            'city' => is_array($city) ? ($city[app()->getLocale()] ?? $city['ar'] ?? null) : $city,
            'district' => $address['district'] ?? null,
            'details' => $address['details'] ?? null,
            'lat' => $address['lat'] ?? null,
            'lng' => $address['lng'] ?? null,
        ];
    }

    /**
     * The Slice 10 payment summary: the payment that confirmed the order, else her latest attempt.
     *
     * @return array<string, mixed>|null
     */
    protected function orderPayment(StoreOrder $order, bool $latestAttempt): ?array
    {
        $payment = $order->payment_id !== null ? $order->payment : ($latestAttempt ? $order->payments()->latest('id')->first() : null);
        if (! $payment instanceof Payment) {
            return null;
        }

        // Settled refunds only (Slice 10 `refunded_amount` also reserves refunds still in progress).
        $refunded = $payment->settledRefundCents();
        $card = $payment->card;

        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'status' => self::enumValue($payment->status),
            'status_label' => self::enumLabel($payment->status),
            'method' => self::enumValue($payment->method),
            'card' => is_array($card) ? ['brand' => $card['brand'] ?? null, 'last4' => $card['last4'] ?? null] : null,
            'amount' => $this->money($payment->amount),
            'refunded_amount' => $this->money(StoreMoney::amount($refunded)),
            'refund' => [
                'status' => $payment->refundState()->value, // none | in_progress | partial | full
                'refunded_amount' => $this->money(StoreMoney::amount($refunded)),
            ],
        ];
    }

    protected static function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    protected static function enumLabel(mixed $value): ?string
    {
        return $value instanceof BackedEnum && method_exists($value, 'label') ? $value->label() : null;
    }
}
