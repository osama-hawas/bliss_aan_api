<?php

namespace App\Http\Requests\Api\V1\Client\Bookings;

use App\Enums\ReviewTag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Review body (D129): rating 1–5, optional comment, optional approved tags (distinct, at most 5).
 */
class SubmitReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'array', 'max:5'],
            'tags.*' => ['string', 'distinct', Rule::enum(ReviewTag::class)],
        ];
    }
}
