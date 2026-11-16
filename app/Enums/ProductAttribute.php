<?php

namespace App\Enums;

/**
 * Variant attribute codes (BRD Req 46: size / colour). Labels live in the `store` language files.
 */
enum ProductAttribute: string
{
    case Size = 'size';
    case Color = 'color';

    public function label(): string
    {
        return __('store.attributes.'.$this->value);
    }
}
