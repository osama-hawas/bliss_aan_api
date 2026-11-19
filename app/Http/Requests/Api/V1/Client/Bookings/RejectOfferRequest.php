<?php

namespace App\Http\Requests\Api\V1\Client\Bookings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting an offer: the reason is optional (QA BUG-012, owner decision 2026-11-19).
 */
class RejectOfferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
