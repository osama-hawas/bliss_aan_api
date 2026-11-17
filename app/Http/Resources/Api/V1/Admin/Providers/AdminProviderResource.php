<?php

namespace App\Http\Resources\Api\V1\Admin\Providers;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Provider;
use App\Services\Providers\ProviderRoster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An activated provider in the dashboard (`providers.view`). `is_visible` = D75 visibility to clients.
 *
 * @mixin Provider
 */
class AdminProviderResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->display_name,
            'account_name' => $this->user?->name,
            'account_type' => $this->account_type->value,
            'account_type_label' => $this->account_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'city_id' => $this->city_id,
            'city' => $this->city?->getTranslations('name') ?: null,
            'phone' => $this->user?->phone,
            'is_verified' => (bool) $this->is_verified,
            'is_featured' => (bool) $this->is_featured,
            // Prefetched for list pages (`ProviderRoster::list`); one query for a single provider.
            'is_visible' => array_key_exists('is_visible', $this->resource->getAttributes()) ? (bool) $this->resource->getAttributes()['is_visible'] : app(ProviderRoster::class)->isVisible($this->resource),
            'rating' => $this->rating_count > 0 ? $this->rating_avg : null,
            'reviews_count' => (int) $this->rating_count,
            'services_count' => (int) $this->services_count,
            'activated_at' => $this->dateTime($this->activated_at),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
