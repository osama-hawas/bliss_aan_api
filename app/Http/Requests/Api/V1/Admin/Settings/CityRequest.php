<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use App\Http\Requests\Concerns\ValidatesTranslations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A city name in both languages (it reaches every picker) and an optional position (D308).
 */
class CityRequest extends FormRequest
{
    use ValidatesTranslations;

    public function rules(): array
    {
        return [
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:60'],
            'name.en' => ['required', 'string', 'max:60'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireOneLanguage($validator, ['name']);
    }

    /**
     * @return array{name: array{ar: string, en: string}, sort: ?int}
     */
    public function city(): array
    {
        return ['name' => $this->translations('name'), 'sort' => $this->filled('sort') ? (int) $this->validated('sort') : null];
    }
}
