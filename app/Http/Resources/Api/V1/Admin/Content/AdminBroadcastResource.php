<?php

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Enums\BroadcastStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\BroadcastCampaign;
use App\Services\Content\ContentTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BroadcastCampaign
 */
class AdminBroadcastResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $missing = ContentTranslations::missing($this->resource, ['title', 'body']);

        return [
            'id' => $this->id,
            'title' => ContentTranslations::of($this->resource, 'title'),
            'body' => ContentTranslations::of($this->resource, 'body'),
            'missing' => $missing,
            'audience' => $this->app->value,
            'city_id' => $this->city_id,
            'city' => $this->city ? ['id' => $this->city->id, 'name' => $this->city->getTranslations('name')] : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'recipients_count' => $this->recipients_count,
            'scheduled_at' => $this->dateTime($this->scheduled_at),
            'sent_at' => $this->dateTime($this->sent_at),
            'finished_at' => $this->dateTime($this->finished_at),
            'cancel_reason' => $this->cancel_reason,
            'created_by' => $this->creator?->name,
            'sent_by' => $this->sender?->name,
            'actions' => [
                'can_edit' => $this->status === BroadcastStatus::Draft,
                'can_send' => $this->status->canMoveTo(BroadcastStatus::Sending) && $missing === [],
                'can_schedule' => $this->status === BroadcastStatus::Draft && $missing === [],
                'can_cancel' => $this->status->canMoveTo(BroadcastStatus::Cancelled),
            ],
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
