<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `media_id` from `POST shared/uploads` (context `avatar`), or null to remove the photo.
 */
class SetAvatarRequest extends FormRequest
{
    public function rules(): array
    {
        return ['media_id' => ['present', 'nullable', 'integer']];
    }
}
