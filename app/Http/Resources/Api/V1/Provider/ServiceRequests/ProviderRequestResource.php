<?php

namespace App\Http\Resources\Api\V1\Provider\ServiceRequests;

use App\Enums\ExecutionPlace;
use App\Http\Resources\Concerns\DescribesOffer;
use App\Http\Resources\Concerns\DescribesServiceRequest;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Media;
use App\Models\Offer;
use App\Models\ServiceRequestRecipient;
use App\Services\Availability\ProviderAvailability;
use App\Services\ServiceRequests\RequestAttachments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request as one recipient sees it before booking — Privacy Model A (D56): city, district and the client's first
 * name only. Never the address details, label, pin, the client's phone or last name, or other recipients.
 * Attachments only while she is `new`/`offer_sent` (D58). List items leave out notes and attachments.
 * Slice 5b: her own offers only (never a competitor's): `current_offer` (her newest, any status) on every item, the
 * full `offers` list on the details.
 *
 * @mixin ServiceRequestRecipient
 */
class ProviderRequestResource extends JsonResource
{
    use DescribesOffer, DescribesServiceRequest, FormatsApiValues;

    private bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $recipient = $this->resource;
        $r = $recipient->serviceRequest;
        $core = $this->requestCore($r);
        $addons = $core['addons'];
        unset($core['guests_count'], $core['addons']);

        return [
            ...array_slice($core, 0, 4, true),
            'request_status' => $r->status->value,
            'request_status_label' => $r->status->label(),
            'status' => $recipient->status->value,
            'status_label' => $recipient->status->label(),
            'service' => ['id' => $r->service_id, 'name' => $r->service?->name],
            ...array_slice($core, 4, null, true),
            'district' => $r->execution_place === ExecutionPlace::EventLocation ? ($r->address['district'] ?? null) : null,
            // D56: the winning provider sees the exact address and pin once booked (event location only).
            // The key exists only for her once booked: before booking the response never carries an address (D56).
            ...($this->won() ? ['address' => $r->execution_place === ExecutionPlace::EventLocation ? $r->address : null] : []),
            'booking' => $this->won() ? [
                'id' => $r->booking->id,
                'reference' => $r->booking->reference,
                'status' => $r->booking->status->value,
                'status_label' => $r->booking->status->label(),
                'total' => $this->money($r->booking->total),
                'confirmed_at' => $this->dateTime($r->booking->confirmed_at),
            ] : null,
            'client' => ['first_name' => $this->firstName($r->client?->name)],
            'guests_count' => $r->guests_count,
            'addons' => $addons,
            ...($this->detailed ? ['notes' => $r->notes, 'attachments' => $this->attachments($request)] : []),
            'decline' => $recipient->declined_at ? [
                'reason' => $recipient->declineReason ? ['id' => $recipient->declineReason->id, 'code' => $recipient->declineReason->code, 'name' => $recipient->declineReason->name] : null,
                'explanation' => $recipient->decline_explanation,
                'declined_at' => $this->dateTime($recipient->declined_at),
            ] : null,
            'current_offer' => ($current = $this->offers()->first()) ? $this->offerCore($current, $this->detailed) : null,
            ...($this->detailed ? ['offers' => $this->offers()->map(fn (Offer $o) => $this->offerCore($o))->values()->all()] : []),
            'calendar_conflict' => app(ProviderAvailability::class)->hasConflict($recipient),
            'received_at' => $this->dateTime($recipient->created_at),
            'seen_at' => $this->dateTime($recipient->seen_at),
        ];
    }

    /**
     * Slice 5c: this request was booked with her.
     */
    private function won(): bool
    {
        $r = $this->resource->serviceRequest;
        $booking = $r->relationLoaded('booking') ? $r->booking : $r->booking()->first();
        $r->setRelation('booking', $booking);

        return $booking !== null && $booking->provider_id === $this->resource->provider_id;
    }

    /**
     * Her offers on this request, newest first. Filtered by provider again, whatever was eager loaded.
     */
    private function offers()
    {
        $recipient = $this->resource;

        return $recipient->serviceRequest->offers->where('provider_id', $recipient->provider_id)->values();
    }

    private function attachments(Request $request): array
    {
        $files = app(RequestAttachments::class);
        $r = $this->resource->serviceRequest;
        if (! $this->resource->status->isActive() || ! $files->allowed($request->user(), $r)) {
            return [];
        }

        return $r->attachments->map(fn (Media $m) => $files->present($m))->values()->all();
    }

    private function firstName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : preg_split('/\s+/u', $name)[0];
    }
}
