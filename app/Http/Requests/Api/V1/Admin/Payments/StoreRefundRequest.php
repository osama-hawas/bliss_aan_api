<?php

namespace App\Http\Requests\Api\V1\Admin\Payments;

use App\Models\Booking;
use App\Models\CancellationRequest;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `Idempotency-Key` header (uuid), `amount` (> 0, 2 decimals), required `reason` (D187), optional cancellation request
 * of the payment's own booking.
 */
class StoreRefundRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'cancellation_request_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $booking = Booking::query()->where('payment_id', (int) $this->route('payment'))->value('id');
                if (! $booking || ! CancellationRequest::query()->whereKey($value)->where('booking_id', $booking)->exists()) {
                    $fail(__('validation.exists', ['attribute' => 'cancellation_request_id']));
                }
            }],
        ];
    }
}
