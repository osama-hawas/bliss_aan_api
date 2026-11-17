<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared rules of the admin catalog forms (docs/slice-16-contract.md §4). POST creates (required fields), PUT updates
 * (every key optional, at least one). Bilingual fields are `{ar, en}`, both required when the field is sent.
 */
abstract class CatalogFormRequest extends FormRequest
{
    /**
     * Keys a PUT may send; at least one is required.
     *
     * @return list<string>
     */
    abstract protected function fields(): array;

    protected function creating(): bool
    {
        return $this->isMethod('post');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function text(string $field, int $min, int $max, bool $requiredOnCreate, bool $nullable = false): array
    {
        $presence = $this->creating() && $requiredOnCreate ? 'required' : 'sometimes';

        return [
            $field => array_values(array_filter([$presence, $nullable ? 'nullable' : null, 'array:ar,en'])),
            "{$field}.ar" => ["required_with:{$field}", 'string', "min:{$min}", "max:{$max}"],
            "{$field}.en" => ["required_with:{$field}", 'string', "min:{$min}", "max:{$max}"],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function image(): array
    {
        return ['image_media_id' => ['sometimes', 'nullable', 'integer']];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $this->creating() && ! collect($this->fields())->contains(fn (string $key) => $this->has($key))) {
                $validator->errors()->add($this->fields()[0], __('catalog.validation.at_least_one'));
            }
        }];
    }

    /**
     * Validated keys only (absent = unchanged), with ids cast and money normalised to a 2-decimal string.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        foreach (['image_media_id', 'category_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        foreach (['addon_ids', 'service_ids'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = array_values(array_unique(array_map('intval', $data[$key] ?? [])));
            }
        }
        if (array_key_exists('guide_price', $data) && $data['guide_price'] !== null) {
            $data['guide_price'] = Money::normalize((string) $data['guide_price']);
        }

        return $data;
    }
}
