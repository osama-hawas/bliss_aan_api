<?php

namespace App\Http\Resources\Api\V1\Client\ServiceRequests;

use App\Enums\ActivityVisibility;
use App\Http\Resources\Concerns\DescribesServiceRequest;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Media;
use App\Models\ServiceRequest;
use App\Services\ServiceRequests\RequestAttachments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The client's own request (§6.3). The provider is null for open requests (recipients are never listed to her).
 * List items leave out the address, notes, attachments and history; call detailed() for the details screen.
 *
 * @mixin ServiceRequest
 */
class ClientRequestResource extends JsonResource
{
    use DescribesServiceRequest, FormatsApiValues, PublicMediaUrl;

    private bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $core = $this->requestCore($r);

        $data = [
            ...array_slice($core, 0, 4, true),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'service' => [
                'id' => $r->service_id,
                'name' => $r->service?->name,
                'category' => ['id' => $r->service?->category_id, 'name' => $r->service?->category?->name],
            ],
            'provider' => $r->targetProvider ? [
                'id' => $r->targetProvider->id,
                'display_name' => $r->targetProvider->display_name,
                'avatar_url' => $this->publicUrl($r->targetProvider->avatar),
            ] : null,
            ...array_slice($core, 4, null, true),
        ];
        if ($this->detailed) {
            $data['address'] = $r->address;
            $data['notes'] = $r->notes;
            $data['attachments'] = $this->attachments($request, $r);
        }
        // Slice 5b: live offers (active and inside their validity) the client can compare.
        $data['offers_count'] = (int) ($r->live_offers_count ?? $r->offers()->live()->count());
        $data['cancelled_at'] = $this->dateTime($r->cancelled_at);
        $data['cancel_reason'] = $r->cancel_reason;
        if ($this->detailed) {
            $data['history'] = $this->history($r, [ActivityVisibility::All, ActivityVisibility::Client]);
        }
        $data['created_at'] = $this->dateTime($r->created_at);

        return $data;
    }

    private function attachments(Request $request, ServiceRequest $r): array
    {
        $files = app(RequestAttachments::class);
        $allowed = $files->allowed($request->user(), $r);

        return $allowed ? $r->attachments->map(fn (Media $m) => $files->present($m))->values()->all() : [];
    }
}
