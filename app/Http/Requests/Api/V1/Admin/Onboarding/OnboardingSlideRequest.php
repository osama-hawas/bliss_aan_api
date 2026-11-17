<?php

namespace App\Http\Requests\Api\V1\Admin\Onboarding;

use App\Enums\Audience;
use App\Http\Requests\Concerns\ValidatesTranslations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST: `app` required) or edit (PUT: `app` ignored, D362) an onboarding slide. Image optional (D361).
 */
class OnboardingSlideRequest extends FormRequest
{
    use ValidatesTranslations;

    public function rules(): array
    {
        return [
            'app' => [$this->isMethod('post') ? 'required' : 'nullable', Rule::enum(Audience::class)],
            ...$this->translationRules('title', 120),
            ...$this->translationRules('text', 500),
            'media_id' => ['nullable', 'integer'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireOneLanguage($validator, ['title', 'text']);
    }

    /**
     * @return array{app: ?Audience, title: array{ar: string, en: string}, text: array{ar: string, en: string}, media_id: ?int, remove_image: bool}
     */
    public function slide(): array
    {
        return [
            'app' => $this->filled('app') ? Audience::from($this->validated('app')) : null,
            'title' => $this->translations('title'),
            'text' => $this->translations('text'),
            'media_id' => $this->filled('media_id') ? (int) $this->validated('media_id') : null,
            'remove_image' => $this->boolean('remove_image'),
        ];
    }
}
