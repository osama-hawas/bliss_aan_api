<?php

namespace App\Http\Resources\Api\V1\Admin\Staff;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member (admin user) for the dashboard. One role each (D167).
 *
 * @mixin User
 */
class StaffResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        /** @var Role|null $role */
        $role = $this->roles->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $role?->name,
            'role_label' => $role?->displayLabel(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'is_self' => $request->user()?->id === $this->id,
            'last_login_at' => $this->dateTime($this->last_login_at),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
