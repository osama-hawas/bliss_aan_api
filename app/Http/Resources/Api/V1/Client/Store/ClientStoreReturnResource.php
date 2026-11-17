<?php

namespace App\Http\Resources\Api\V1\Client\Store;

use App\Enums\StoreReturnStatus;
use App\Http\Resources\Concerns\DescribesStoreReturn;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\StoreReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her store return (S23, `slice-23-contract.md` §5.3). No staff identities, internal notes or refund failure codes.
 *
 * @mixin StoreReturn
 */
class ClientStoreReturnResource extends JsonResource
{
    use DescribesStoreReturn, FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        /** @var StoreReturn $return */
        $return = $this->resource;

        return [
            ...$this->returnBasics($return),
            'items' => $this->returnItems($return),
            'items_count' => (int) $return->items->sum('quantity'),
            'photos' => $this->returnPhotos($return),
            'refund' => $this->returnRefund($return),
            'timeline' => $this->returnTimeline($return),
            'can_cancel' => $return->status === StoreReturnStatus::Requested,
        ];
    }
}
