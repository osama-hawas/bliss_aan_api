<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\Audience;
use App\Http\Requests\Concerns\ValidatesTranslations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A broadcast campaign draft (D305): text in both languages before sending, one app, optional city.
 */
class BroadcastRequest extends FormRequest
{
    use ValidatesTranslations;

    public function rules(): array
    {
        return [
            ...$this->translationRules('title', 100),
            ...$this->translationRules('body', 500),
            'audience' => ['required', Rule::enum(Audience::class)],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireOneLanguage($validator, ['title', 'body']);
    }

    /**
     * @return array{title: array{ar: string, en: string}, body: array{ar: string, en: string}, app: Audience, city_id: ?int}
     */
    public function campaign(): array
    {
        return [
            'title' => $this->translations('title'),
            'body' => $this->translations('body'),
            'app' => Audience::from($this->validated('audience')),
            'city_id' => $this->filled('city_id') ? (int) $this->validated('city_id') : null,
        ];
    }
}
