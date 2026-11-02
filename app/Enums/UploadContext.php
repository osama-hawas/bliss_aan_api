<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Where an uploaded file will be used. Decides visibility and who may upload it.
 * Allowed types and sizes per context live in app_settings (`uploads.<context>`).
 */
enum UploadContext: string
{
    use HasLabel;

    case ProviderDocument = 'provider_document';
    case Portfolio = 'portfolio';
    case ChatImage = 'chat_image';
    case ChatFile = 'chat_file';
    case ChatVoice = 'chat_voice';
    case BookingUpdate = 'booking_update';
    case CatalogImage = 'catalog_image';
    case Banner = 'banner';

    public function visibility(): MediaVisibility
    {
        return match ($this) {
            self::Portfolio, self::CatalogImage, self::Banner => MediaVisibility::Public,
            default => MediaVisibility::Private,
        };
    }

    /**
     * @return list<UserType>
     */
    public function allowedUserTypes(): array
    {
        return match ($this) {
            self::ProviderDocument, self::Portfolio => [UserType::Provider],
            self::ChatImage, self::ChatFile, self::ChatVoice => [UserType::Client, UserType::Provider],
            self::BookingUpdate => [UserType::Provider],
            self::CatalogImage, self::Banner => [UserType::Admin],
        };
    }

    public function settingKey(): string
    {
        return 'uploads.'.$this->value;
    }
}
