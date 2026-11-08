<?php

namespace App\Http\Requests\Api\V1\Provider\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `media_id` comes from `POST shared/uploads` with context `provider_document` (type and size checked there).
 */
class AttachDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer'],
            'media_id' => ['required', 'integer'],
        ];
    }
}
