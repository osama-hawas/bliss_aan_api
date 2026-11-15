<?php

namespace App\Http\Resources\Concerns;

use App\Models\Offer;
use App\Models\OfferItem;

/**
 * Fields every audience shares for an offer (slice-5b-contract.md §4). Uses FormatsApiValues. The status is the
 * effective one: an active offer past `valid_until` is `expired`.
 */
trait DescribesOffer
{
    protected function offerCore(Offer $o, bool $withItems = true): array
    {
        $status = $o->effectiveStatus();

        return [
            'id' => $o->id,
            'reference' => $o->reference,
            'title' => $o->title,
            ...($withItems ? ['items' => $o->items->map(fn (OfferItem $i) => [
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price' => $this->money($i->unit_price),
                'line_total' => $this->money($i->line_total),
            ])->values()->all()] : []),
            'total' => $this->money($o->total),
            'validity_days' => $o->validity_days,
            'valid_until' => $this->dateTime($o->valid_until),
            'notes' => $o->notes,
            'status' => $status->value,
            'status_label' => $status->label(),
            'is_quick' => $o->is_quick,
            'sent_at' => $this->dateTime($o->sent_at),
            'withdrawn_at' => $this->dateTime($o->withdrawn_at),
            'closed_at' => $this->dateTime($o->closed_at),
        ];
    }
}
