<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\StoreReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store return in the staff list (S23, §6.1).
 *
 * @mixin StoreReturn
 */
class AdminStoreReturnRowResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        /** @var StoreReturn $return */
        $return = $this->resource;

        return [
            'id' => $return->id,
            'reference' => $return->reference,
            'status' => $return->status->value,
            'status_label' => $return->status->label(),
            'order' => ['id' => $return->store_order_id, 'reference' => $return->order?->reference],
            'client' => ['id' => $return->user?->id, 'name' => $return->user?->name, 'phone' => $return->user?->phone],
            'reason_code' => $return->reason_code->value,
            'reason_label' => $return->reason_code->label(),
            'items_count' => (int) ($return->items_count ?? $return->items()->sum('quantity')),
            'refund_amount' => $this->money($return->refund_amount),
            'refund_error' => $return->refund_error,
            'created_at' => $this->dateTime($return->created_at),
        ];
    }
}
