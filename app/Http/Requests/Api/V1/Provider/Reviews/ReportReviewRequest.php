<?php

namespace App\Http\Requests\Api\V1\Provider\Reviews;

use App\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:300'],
        ];
    }
}
