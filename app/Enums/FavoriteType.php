<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * What a client can save as a favorite (`favorites.favoritable_type`).
 */
enum FavoriteType: string
{
    use HasLabel;

    case Provider = 'provider';
    case ProviderService = 'provider_service';
}
