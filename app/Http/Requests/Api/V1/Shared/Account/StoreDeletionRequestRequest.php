<?php

namespace App\Http\Requests\Api\V1\Shared\Account;

use App\Enums\DeletionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * D151: one of the five reason codes; details optional for every reason.
 */
class StoreDeletionRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(DeletionReason::class)],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return __('account.attributes');
    }

    public function reason(): DeletionReason
    {
        return DeletionReason::from($this->validated('reason'));
    }
}
