<?php

namespace App\Http\Resources\Api\V1\Provider\ServiceRequests;

use App\Enums\ExecutionPlace;
use App\Http\Resources\Concerns\DescribesServiceRequest;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Media;
use App\Models\ServiceRequestRecipient;
use App\Services\ServiceRequests\RequestAttachments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request as one recipient sees it before booking — Privacy Model A (D56): city, district and the client's first
 * name only. Never the address details, label, pin, the client's phone or last name, or other recipients.
 * Attachments only while she is `new`/`offer_sent` (D58). List items leave out notes and attachments.
 *
 * @mixin ServiceRequestRecipient
 */
class ProviderRequestResource extends JsonResource
{
    use DescribesServiceRequest, FormatsApiValues;

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
            'client' => ['first_name' => $this->firstName($r->client?->name)],
            'guests_count' => $r->guests_count,
            'addons' => $addons,
            ...($this->detailed ? ['notes' => $r->notes, 'attachments' => $this->attachments($request)] : []),
            'decline' => $recipient->declined_at ? [
                'reason' => $recipient->declineReason ? ['id' => $recipient->declineReason->id, 'code' => $recipient->declineReason->code, 'name' => $recipient->declineReason->name] : null,
                'explanation' => $recipient->decline_explanation,
                'declined_at' => $this->dateTime($recipient->declined_at),
            ] : null,
            'received_at' => $this->dateTime($recipient->created_at),
            'seen_at' => $this->dateTime($recipient->seen_at),
        ];
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
