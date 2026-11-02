<?php

namespace App\Models;

use App\Enums\Audience;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Page extends Model
{
    use HasTranslations;

    public array $translatable = ['title', 'body'];

    protected $fillable = ['slug', 'audience', 'title', 'body', 'is_active'];

    protected function casts(): array
    {
        return [
            'audience' => Audience::class,
            'is_active' => 'boolean',
        ];
    }
}
