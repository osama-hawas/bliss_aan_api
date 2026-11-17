<?php

namespace App\Http\Requests\Api\V1\Client\Store;

use App\Enums\StoreReturnReason;
use App\Services\Store\Returns\StoreReturnRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A return request (S23, §5.1). `Idempotency-Key` header is validated as `idempotency_key`.
 */
class CreateStoreReturnRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::enum(StoreReturnReason::class)],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'photo_media_ids' => ['nullable', 'array', 'max:'.StoreReturnRequests::MAX_PHOTOS],
            'photo_media_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
