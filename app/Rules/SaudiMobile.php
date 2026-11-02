<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SaudiMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Phone::normalizeSaudiMobile($value) === null) {
            $fail('validation.saudi_mobile')->translate();
        }
    }
}
