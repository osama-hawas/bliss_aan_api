<?php

namespace App\Http\Requests\Api\V1\Provider\Payouts;

use App\Rules\ValidSaudiIban;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Holder name + Saudi IBAN (D216). Spaces and lowercase in the IBAN are accepted and normalised.
 */
class SavePayoutAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'holder_name' => ['required', 'string', 'min:2', 'max:70'],
            'iban' => ['required', 'string', 'max:40', new ValidSaudiIban],
        ];
    }
}
