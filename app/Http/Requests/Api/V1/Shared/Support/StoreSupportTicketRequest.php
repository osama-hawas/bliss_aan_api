<?php

namespace App\Http\Requests\Api\V1\Shared\Support;

use App\Enums\TicketRelatedType;
use App\Enums\TicketTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new ticket (D159, D162). The `Idempotency-Key` header is validated as `idempotency_key`; ownership of the related
 * record is checked by SupportTickets after the idempotency replay.
 */
class StoreSupportTicketRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'topic' => ['required', 'string', Rule::enum(TicketTopic::class)],
            'subject' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:1000'],
            'related_type' => ['nullable', 'string', Rule::enum(TicketRelatedType::class), 'required_with:related_id'],
            'related_id' => ['nullable', 'integer', 'min:1', 'required_with:related_type'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return collect($this->validated())->except('idempotency_key')->all();
    }
}
