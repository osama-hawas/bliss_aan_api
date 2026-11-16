<?php

namespace App\Http\Requests\Api\V1\Shared\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Client side: which provider to talk to on her request (§2).
 */
class OpenConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return ['provider_id' => ['required', 'integer']];
    }
}
