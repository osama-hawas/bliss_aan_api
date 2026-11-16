<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Chat message kinds (Slice 7). `system` is reserved for later automatic entries.
 */
enum MessageType: string
{
    use HasLabel;

    case Text = 'text';
    case Image = 'image';
    case File = 'file';
    case Voice = 'voice';
    case OfferCard = 'offer_card';
    case System = 'system';

    /**
     * The upload context a media message must carry, or null when the type takes no file.
     */
    public function uploadContext(): ?UploadContext
    {
        return match ($this) {
            self::Image => UploadContext::ChatImage,
            self::File => UploadContext::ChatFile,
            self::Voice => UploadContext::ChatVoice,
            default => null,
        };
    }

    /**
     * Types a participant may send through the API.
     *
     * @return list<string>
     */
    public static function sendable(): array
    {
        return [self::Text->value, self::Image->value, self::File->value, self::Voice->value, self::OfferCard->value];
    }
}
