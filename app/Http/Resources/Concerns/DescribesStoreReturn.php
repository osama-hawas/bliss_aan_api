<?php

namespace App\Http\Resources\Concerns;

use App\Enums\StoreReturnStatus;
use App\Models\ActivityEvent;
use App\Models\Media;
use App\Models\StoreReturn;
use App\Models\StoreReturnItem;
use App\Services\Media\MediaService;

/**
 * Fields a store return shows to the client and to staff (S23, `slice-23-contract.md` §5.3 / §6.2).
 * Needs FormatsApiValues and PublicMediaUrl on the resource.
 */
trait DescribesStoreReturn
{
    /** @return array<string, mixed> */
    protected function returnBasics(StoreReturn $return): array
    {
        return [
            'id' => $return->id,
            'reference' => $return->reference,
            'status' => $return->status->value,
            'status_label' => $return->status->label(),
            'order' => ['id' => $return->store_order_id, 'reference' => $return->order?->reference],
            'reason_code' => $return->reason_code->value,
            'reason_label' => $return->reason_code->label(),
            'note' => $return->note,
            'subtotal' => $this->money($return->subtotal),
            'discount' => $this->money($return->discount),
            'refund_amount' => $this->money($return->refund_amount),
            'reject_reason' => $return->status === StoreReturnStatus::Rejected ? $return->reject_reason : null,
            'created_at' => $this->dateTime($return->created_at),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function returnItems(StoreReturn $return): array
    {
        return $return->items->map(fn (StoreReturnItem $item) => [
            'order_item_id' => $item->store_order_item_id,
            'variant_id' => $item->product_variant_id,
            'name' => $item->localizedName(),
            'options' => $item->resolvedOptions(),
            'sku' => $item->sku,
            'image_url' => $this->publicUrl($item->variant?->product?->image),
            'unit_price' => $this->money($item->unit_price),
            'quantity' => $item->quantity,
            'line_total' => $this->money($item->line_total),
        ])->values()->all();
    }

    /** Private photos: short-lived signed URLs (the caller is the owner or staff with orders.view). */
    protected function returnPhotos(StoreReturn $return): array
    {
        $media = app(MediaService::class);

        return $return->photos->map(fn (Media $photo) => ['id' => $photo->id, 'url' => $media->url($photo)])->values()->all();
    }

    /** @return array<string, mixed>|null the Slice 10 refund that settled it, else the latest attempt state */
    protected function returnRefund(StoreReturn $return): ?array
    {
        $refund = $return->refund;
        if ($refund !== null) {
            return [
                'status' => self::returnEnum($refund->status),
                'status_label' => $refund->status->label(),
                'amount' => $this->money($refund->amount),
                'refunded_at' => $this->dateTime($return->refunded_at),
            ];
        }
        if ($return->status !== StoreReturnStatus::Received) {
            return null;
        }

        return [
            'status' => $return->refund_error ? 'failed' : 'in_progress',
            'status_label' => __('enums.refund_status.'.($return->refund_error ? 'failed' : 'in_progress')),
            'amount' => $this->money($return->refund_amount),
            'refunded_at' => null,
        ];
    }

    /** Client-visible status moves (visibility all, with a to_status). */
    protected function returnTimeline(StoreReturn $return): array
    {
        return $return->activity
            ->filter(fn (ActivityEvent $event) => $event->to_status !== null && self::returnEnum($event->visibility) === 'all')
            ->map(fn (ActivityEvent $event) => [
                'status' => $event->to_status,
                'status_label' => StoreReturnStatus::tryFrom($event->to_status)?->label(),
                'at' => $this->dateTime($event->created_at),
            ])->values()->all();
    }

    protected static function returnEnum(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
