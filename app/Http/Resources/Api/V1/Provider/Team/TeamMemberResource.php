<?php

namespace App\Http\Resources\Api\V1\Provider\Team;

use App\Http\Resources\Concerns\DescribesTeamMember;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ProviderTeamMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of her team members (Slice 13 §4). Provider only; never shown to clients or staff (D230).
 *
 * @mixin ProviderTeamMember
 */
class TeamMemberResource extends JsonResource
{
    use DescribesTeamMember, FormatsApiValues;

    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            ...$this->teamMemberItem($m),
            'phone' => $m->phone,
            'status' => $m->status->value,
            'status_label' => $m->status->label(),
            'disabled_at' => $this->dateTime($m->disabled_at),
            'active_assignments_count' => (int) ($m->active_assignments_count ?? 0),
            'created_at' => $this->dateTime($m->created_at),
            'updated_at' => $this->dateTime($m->updated_at),
        ];
    }
}
