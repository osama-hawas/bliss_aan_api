<?php

namespace App\Http\Requests\Api\V1\Shared\Chat;

use App\Enums\MessageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * slice-7-contract.md §4: `client_uuid` dedupes; text needs a body; image/file/voice need `media_id`; offer_card needs
 * `offer_id`. Ownership and context of the file and offer are checked by ChatMessenger under lock.
 */
class SendMessageRequest extends FormRequest
{
    public function rules(): array
    {
        $media = in_array($this->input('type'), [MessageType::Image->value, MessageType::File->value, MessageType::Voice->value], true);

        return [
            'client_uuid' => ['required', 'uuid'],
            'type' => ['required', Rule::in(MessageType::sendable())],
            'body' => [$this->input('type') === MessageType::Text->value ? 'required' : 'nullable', 'string', 'max:2000'],
            'media_id' => [$media ? 'required' : 'prohibited', 'integer'],
            'offer_id' => [$this->input('type') === MessageType::OfferCard->value ? 'required' : 'prohibited', 'integer'],
        ];
    }
}
