<?php

namespace App\Rules;

use App\Support\SaudiIban;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Saudi IBAN (spaces and lowercase accepted; slice-11-contract.md §7).
 */
class ValidSaudiIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! SaudiIban::isValid($value)) {
            $fail(__('payouts.iban_invalid'));
        }
    }
}
