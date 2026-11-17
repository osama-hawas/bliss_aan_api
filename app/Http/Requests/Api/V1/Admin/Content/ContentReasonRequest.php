<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hiding content, cancelling a campaign, stopping a city or a document type: a reason is required (D301).
 */
class ContentReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
