<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\FormatsCents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One reconciliation finding in the common shape of Reconciliation::query().
 */
class ReconciliationRowResource extends JsonResource
{
    use FormatsApiValues, FormatsCents;

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $created = $r->created_at ? Carbon::parse($r->created_at, 'UTC') : null;

        return [
            'id' => (int) $r->id,
            'kind' => $r->kind,
            'reference' => $r->reference,
            'status' => $r->status,
            'amount' => $this->cents($r->amount_cents),
            'provider' => $r->provider_id ? ['id' => (int) $r->provider_id, 'display_name' => $r->provider_name] : null,
            'client' => $r->client_id ? ['id' => (int) $r->client_id, 'name' => $r->client_name] : null,
            'link' => $r->link_type ? ['type' => $r->link_type, 'id' => (int) $r->link_id] : null,
            'age_minutes' => $created ? (int) $created->diffInMinutes(now(), true) : null,
            'created_at' => $this->dateTime($created),
            'detail' => $r->detail,
        ];
    }
}
