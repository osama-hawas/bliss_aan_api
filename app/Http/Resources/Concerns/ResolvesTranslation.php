<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A translatable field in the request language, falling back to the other language, never an empty string (D158).
 */
trait ResolvesTranslation
{
    protected function translated(Model $model, string $field): ?string
    {
        $text = $model->getTranslation($field, app()->getLocale(), false);
        if ($text !== '' && $text !== null) {
            return $text;
        }

        foreach ($model->getTranslations($field) as $value) {
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }

        return null;
    }
}
