<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Translated label for an enum case, read from lang/{locale}/enums.php.
 * The group key is the enum's short class name in snake_case (UserStatus → user_status).
 */
trait HasLabel
{
    public function label(): string
    {
        return __('enums.'.Str::snake(class_basename(static::class)).'.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
