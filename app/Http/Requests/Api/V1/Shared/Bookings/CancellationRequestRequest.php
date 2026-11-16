<?php

namespace App\Http\Requests\Api\V1\Shared\Bookings;

use App\Enums\CancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Booking cancellation request body (D127): a reason code and an explanation, both required. The code must belong
 * to the caller's side; BookingCancellations checks that.
 */
class CancellationRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::enum(CancellationReason::class)],
            'reason_text' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }

    public function reason(): CancellationReason
    {
        return CancellationReason::from($this->validated('reason_code'));
    }
}
