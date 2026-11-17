<?php

namespace App\Http\Requests\Api\V1\Shared\Catalog;

use App\Models\City;
use Illuminate\Foundation\Http\FormRequest;

class ShowHomeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['city_id' => ['nullable', 'integer']];
    }

    /**
     * An unknown or stopped city (S20 D308: staff may stop the city the app still has selected) counts as no city, so
     * the home still loads with the all-cities content instead of a 422 (review L9).
     */
    public function cityId(): ?int
    {
        if (! $this->filled('city_id')) {
            return null;
        }
        $id = (int) $this->validated('city_id');

        return City::query()->whereKey($id)->where('is_active', true)->exists() ? $id : null;
    }
}
