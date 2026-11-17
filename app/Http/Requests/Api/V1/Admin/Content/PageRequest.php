<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\Audience;
use App\Http\Requests\Concerns\ValidatesTranslations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A page of one app (D302). Body = D163 plain text.
 */
class PageRequest extends FormRequest
{
    use ValidatesTranslations;

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'audience' => ['required', Rule::enum(Audience::class)],
            ...$this->translationRules('title', 150),
            ...$this->translationRules('body', 30000),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireOneLanguage($validator, ['title', 'body']);
    }

    /**
     * @return array{slug: string, audience: Audience, title: array{ar: string, en: string}, body: array{ar: string, en: string}}
     */
    public function page(): array
    {
        return [
            'slug' => $this->validated('slug'),
            'audience' => Audience::from($this->validated('audience')),
            'title' => $this->translations('title'),
            'body' => $this->translations('body'),
        ];
    }
}
