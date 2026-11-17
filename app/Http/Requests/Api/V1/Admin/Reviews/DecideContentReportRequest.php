<?php

namespace App\Http\Requests\Api\V1\Admin\Reviews;

use App\Enums\ContentReportDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Decide a content report (D262).
 */
class DecideContentReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ContentReportDecision::class)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
