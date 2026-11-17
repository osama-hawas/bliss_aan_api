<?php

namespace App\Http\Resources\Api\V1\Admin\Roles;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Role;
use App\Services\Staff\RestrictedPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role with its permission set. `updated_*` come from the role's latest audit row (null = default mapping).
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $audit = $this->relationLoaded('latestAudit') ? $this->getRelation('latestAudit') : null;
        $superAdmin = $this->isSuperAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->displayLabel(),
            'labels' => $this->is_system ? null : $this->label,
            'is_system' => (bool) $this->is_system,
            'is_editable' => ! $superAdmin,
            'restricted_permissions' => $superAdmin ? [] : RestrictedPermissions::SUPER_ADMIN_ONLY,
            'permissions' => $this->permissions->pluck('name')->sort()->values(),
            'staff_count' => (int) ($this->staff_count ?? 0),
            'updated_at' => $this->dateTime($audit?->created_at),
            'updated_by' => $audit?->actor?->name,
        ];
    }
}
