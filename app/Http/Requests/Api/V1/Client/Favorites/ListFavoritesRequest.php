<?php

namespace App\Http\Requests\Api\V1\Client\Favorites;

use App\Enums\FavoriteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFavoritesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.type' => ['nullable', Rule::enum(FavoriteType::class)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function type(): ?FavoriteType
    {
        return $this->filled('filter.type') ? FavoriteType::from($this->validated('filter.type')) : null;
    }
}
