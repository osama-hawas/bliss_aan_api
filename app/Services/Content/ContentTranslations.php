<?php

namespace App\Services\Content;

use Illuminate\Database\Eloquent\Model;

/**
 * Arabic and English text of admin-edited content (Slice 20, D301): both are required before it reaches the apps.
 */
final class ContentTranslations
{
    public const LANGUAGES = ['ar', 'en'];

    /**
     * Trimmed {ar, en}; a missing language becomes ''.
     *
     * @param  array<string, mixed>|null  $input
     * @return array{ar: string, en: string}
     */
    public static function clean(?array $input): array
    {
        $out = [];
        foreach (self::LANGUAGES as $lang) {
            $out[$lang] = trim((string) ($input[$lang] ?? ''));
        }

        return $out;
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function of(Model $model, string $field): array
    {
        $out = [];
        foreach (self::LANGUAGES as $lang) {
            $out[$lang] = trim((string) $model->getTranslation($field, $lang, false));
        }

        return $out;
    }

    /**
     * Languages left empty in any of the fields.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function missing(Model $model, array $fields): array
    {
        return array_values(array_filter(self::LANGUAGES, function (string $lang) use ($model, $fields) {
            foreach ($fields as $field) {
                if (trim((string) $model->getTranslation($field, $lang, false)) === '') {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  array<string, array{ar: string, en: string}>  $values  field => {ar, en}
     * @return list<string>
     */
    public static function missingIn(array $values): array
    {
        return array_values(array_filter(self::LANGUAGES, function (string $lang) use ($values) {
            foreach ($values as $text) {
                if (($text[$lang] ?? '') === '') {
                    return true;
                }
            }

            return false;
        }));
    }
}
