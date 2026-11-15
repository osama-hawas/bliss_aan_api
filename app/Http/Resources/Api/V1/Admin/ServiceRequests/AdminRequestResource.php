<?php

namespace App\Http\Resources\Api\V1\Admin\ServiceRequests;

use App\Http\Resources\Api\V1\Client\ServiceRequests\ClientRequestResource;
use App\Http\Resources\Concerns\DescribesOffer;
use App\Models\Media;
use App\Models\Offer;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Services\ServiceRequests\RequestAttachments;
use Illuminate\Http\Request;

/**
 * Dashboard view (`bookings.view`, D57/D64): the complete request, including the exact address, pin and client
 * phone, every recipient with her decline, and the full history (internal entries too). File URLs only with
 * `requests.attachments.view` (D58); without it `url` is null. List items stay short. Slice 5b: every offer of
 * every provider, in every status (superseded and withdrawn too).
 *
 * @mixin ServiceRequest
 */
class AdminRequestResource extends ClientRequestResource
{
    use DescribesOffer;

    private bool $full = false;

    public function detailed(): static
    {
        $this->full = true;

        return parent::detailed();
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $data = parent::toArray($request);

        $data['client'] = ['id' => $r->client_id, 'name' => $r->client?->name, 'phone' => $r->client?->phone];
        if (! $this->full) {
            $data['recipients_count'] = $r->recipients_count ?? $r->recipients()->count();

            return $data;
        }

        $files = app(RequestAttachments::class);
        $withUrl = $files->allowed($request->user(), $r);
        $data['attachments'] = $r->attachments->map(fn (Media $m) => $files->present($m, $withUrl))->values()->all();
        $data['history'] = $this->history($r, null);
        $data['recipients'] = $r->recipients->map(fn (ServiceRequestRecipient $x) => [
            'provider' => ['id' => $x->provider_id, 'display_name' => $x->provider?->display_name],
            'status' => $x->status->value,
            'status_label' => $x->status->label(),
            'seen_at' => $this->dateTime($x->seen_at),
            'declined_at' => $this->dateTime($x->declined_at),
            'decline' => $x->declined_at ? [
                'reason' => $x->declineReason ? ['code' => $x->declineReason->code, 'name' => $x->declineReason->name] : null,
                'explanation' => $x->decline_explanation,
            ] : null,
            'closed_at' => $this->dateTime($x->closed_at),
        ])->values()->all();

        $data['offers'] = $r->offers->map(fn (Offer $o) => [
            ...$this->offerCore($o),
            'provider' => ['id' => $o->provider_id, 'display_name' => $o->provider?->display_name],
        ])->values()->all();

        return $data;
    }
}
