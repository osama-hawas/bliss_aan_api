<?php

namespace App\Http\Requests\Api\V1\Shared\Chat;

use App\Enums\ConversationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListConversationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
