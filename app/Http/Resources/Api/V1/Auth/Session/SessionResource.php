<?php

namespace App\Http\Resources\Api\V1\Auth\Session;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Login/register result: the token plus the user.
 *
 * @property array{user: User, token: array{access_token: string, token_type: string, expires_in: int}} $resource
 */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource['token'],
            'user' => (new MeResource($this->resource['user']->loadMissing('city')))->resolve($request),
        ];
    }
}
