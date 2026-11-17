<?php

namespace App\Http\Resources\Api\V1\Admin\Clients;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client row in the dashboard (`clients.view`). Store orders do not exist yet: `orders_count` is null.
 *
 * @mixin User
 */
class AdminClientResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'city_id' => $this->city_id,
            'city' => $this->city?->getTranslations('name') ?: null,
            'locale' => $this->locale,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'requests_count' => (int) $this->requests_count,
            'bookings_count' => (int) $this->bookings_count,
            'orders_count' => null,
            'last_login_at' => $this->dateTime($this->last_login_at),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
