<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\Audience;
use App\Http\Requests\Concerns\ValidatesTranslations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A question of one app's support screen (D303).
 */
class FaqRequest extends FormRequest
{
    use ValidatesTranslations;

    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::enum(Audience::class)],
            ...$this->translationRules('question', 300),
            ...$this->translationRules('answer', 5000),
            'sort' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireOneLanguage($validator, ['question', 'answer']);
    }

    /**
     * @return array{audience: Audience, question: array{ar: string, en: string}, answer: array{ar: string, en: string}, sort: ?int}
     */
    public function faq(): array
    {
        return [
            'audience' => Audience::from($this->validated('audience')),
            'question' => $this->translations('question'),
            'answer' => $this->translations('answer'),
            'sort' => $this->filled('sort') ? (int) $this->validated('sort') : null,
        ];
    }
}
