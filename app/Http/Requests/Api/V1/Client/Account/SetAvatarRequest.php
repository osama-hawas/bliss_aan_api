<?php

namespace App\Http\Requests\Api\V1\Client\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D153: an uploaded `avatar` file id, or null to remove the photo.
 */
class SetAvatarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'media_id' => ['present', 'nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return __('account.attributes');
    }
}
