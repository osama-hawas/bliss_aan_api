<?php

namespace App\Http\Requests\Api\V1\Shared\Wallet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create / update body: the optional label is the only editable information. Any balance or money field is
 * refused (D106: the balance cannot be set through the API).
 */
class WalletRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
            'balance' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
        ];
    }
}
