<?php

namespace App\Models;

use App\Enums\DocumentAppliesTo;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class DocumentType extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['applies_to', 'is_required', 'name', 'allowed_mimes', 'max_size_kb', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'applies_to' => DocumentAppliesTo::class,
            'is_required' => 'boolean',
            'allowed_mimes' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
