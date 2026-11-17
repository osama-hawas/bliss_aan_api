<?php

namespace App\Http\Requests\Concerns;

use App\Services\Content\ContentTranslations;
use Illuminate\Validation\Validator;

/**
 * {ar, en} text fields of admin content (Slice 20): each language optional, at least one filled.
 */
trait ValidatesTranslations
{
    /**
     * @return array<string, list<string>>
     */
    protected function translationRules(string $field, int $max, bool $required = true): array
    {
        return [
            $field => [$required ? 'required' : 'nullable', 'array'],
            "{$field}.ar" => ['nullable', 'string', "max:{$max}"],
            "{$field}.en" => ['nullable', 'string', "max:{$max}"],
        ];
    }

    /**
     * @param  list<string>  $fields
     */
    protected function requireOneLanguage(Validator $validator, array $fields): void
    {
        $validator->after(function (Validator $v) use ($fields) {
            foreach ($fields as $field) {
                $text = ContentTranslations::clean((array) $this->input($field, []));
                if ($text['ar'] === '' && $text['en'] === '') {
                    $v->errors()->add("{$field}.ar", __('content.one_language_required'));
                }
            }
        });
    }

    /**
     * @return array{ar: string, en: string}
     */
    public function translations(string $field): array
    {
        return ContentTranslations::clean((array) $this->validated($field, []));
    }
}
