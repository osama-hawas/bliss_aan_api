<?php

namespace App\Http\Resources\Api\V1\Auth\Session;

use App\Enums\UserType;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own account.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'city' => $this->city ? ['id' => $this->city->id, 'name' => $this->city->name] : null,
            'birth_date' => $this->date($this->birth_date),
            'locale' => $this->locale,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'last_login_at' => $this->dateTime($this->last_login_at),
            'created_at' => $this->dateTime($this->created_at),
            // Providers only: the account state the app routes on (application under review, sent back,
            // suspended, active). Details come from `GET provider/application`.
            'provider' => $this->when($this->isType(UserType::Provider) && $this->provider !== null, fn () => [
                'id' => $this->provider->id,
                'status' => $this->provider->status->value,
                'status_label' => $this->provider->status->label(),
            ]),
            // Admins only: what the dashboard may show. The API checks permissions on every route.
            'roles' => $this->when($this->isType(UserType::Admin), fn () => $this->getRoleNames()->values()),
            'permissions' => $this->when($this->isType(UserType::Admin), fn () => $this->getAllPermissions()->pluck('name')->sort()->values()),
        ];
    }
}
