<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The count staff saw on the confirmation dialog; a different live count → 409 AUDIENCE_CHANGED (D305).
 */
class SendBroadcastRequest extends FormRequest
{
    public function rules(): array
    {
        return ['expected_count' => ['required', 'integer', 'min:0']];
    }
}
