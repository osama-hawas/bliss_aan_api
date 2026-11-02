<?php

namespace App\Models;

use App\Enums\PolicyType;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A versioned operating policy. Offers/bookings/orders snapshot the version they were made under.
 */
class PolicyVersion extends Model
{
    use HasTranslations;

    public array $translatable = ['content'];

    protected $fillable = ['type', 'version', 'content', 'values', 'effective_from'];

    protected function casts(): array
    {
        return [
            'type' => PolicyType::class,
            'values' => 'array',
            'effective_from' => 'datetime',
        ];
    }
}
