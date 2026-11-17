<?php

namespace App\Http\Requests\Api\V1\Shared\Content;

use App\Enums\Audience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FAQs and pages are public; the app is chosen by `app`, never by the token (D158).
 */
class ContentAppRequest extends FormRequest
{
    public function rules(): array
    {
        return ['app' => ['required', 'string', Rule::enum(Audience::class)]];
    }

    public function app(): Audience
    {
        return Audience::from($this->validated('app'));
    }
}
