<?php

namespace App\Models;

use App\Enums\Audience;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Faq extends Model
{
    use HasTranslations;

    public array $translatable = ['question', 'answer'];

    protected $fillable = ['question', 'answer', 'audience', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'audience' => Audience::class,
            'is_active' => 'boolean',
        ];
    }
}
