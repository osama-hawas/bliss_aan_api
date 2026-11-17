<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A promo code for staff (`slice-9b-contract.md` §8.1). `value` is a plain decimal (percent or SAR).
 *
 * @mixin PromoCode
 */
class AdminPromoCodeResource extends JsonResource
{
    use FormatsApiValues;

    public function __construct($resource, private readonly bool $withUses = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'value' => $this->value,
            'min_amount' => $this->money($this->min_amount),
            'usage_limit' => $this->usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'starts_at' => $this->dateTime($this->starts_at),
            'ends_at' => $this->dateTime($this->ends_at),
            'is_active' => $this->is_active,
            'used_count' => (int) ($this->used_count ?? 0),
            'state' => $this->resource->state(),
            'created_at' => $this->dateTime($this->created_at),
            'recent_uses' => $this->when($this->withUses, fn () => $this->resource->usages()->with(['order:id,reference', 'user:id,name'])
                ->latest('id')->limit(20)->get()
                ->map(fn (PromoCodeUsage $use) => [
                    'order_id' => $use->store_order_id,
                    'order_reference' => $use->order?->reference,
                    'client_name' => $use->user?->name,
                    'status' => $use->status->value,
                    'status_label' => $use->status->label(),
                    'created_at' => $this->dateTime($use->created_at),
                ])->values()),
        ];
    }
}
