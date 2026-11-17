<?php

namespace App\Http\Requests\Api\V1\Admin\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * New 1-based position of a slide among the slides of its app (D363). Larger than the count = last.
 */
class MoveOnboardingSlideRequest extends FormRequest
{
    public function rules(): array
    {
        return ['position' => ['required', 'integer', 'min:1', 'max:1000']];
    }
}
