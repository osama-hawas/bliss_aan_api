<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MediaVisibility: string
{
    use HasLabel;

    case Private = 'private';
    case Public = 'public';

    public function disk(): string
    {
        return $this === self::Public ? 'public' : 'local';
    }
}
