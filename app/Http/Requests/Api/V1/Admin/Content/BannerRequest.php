<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Http\Requests\Concerns\ValidatesTranslations;
use App\Services\Content\BannerManagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST: image required) or edit (PUT: image optional = keep) a client-app banner (D304).
 */
class BannerRequest extends FormRequest
{
    use ValidatesTranslations;

    public function rules(): array
    {
        return [
            ...$this->translationRules('title', 120),
            'media_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'integer'],
            'destination' => ['nullable', 'array'],
            'destination.type' => ['required_with:destination', Rule::in(array_keys(BannerManagement::DESTINATIONS))],
            'destination.id' => ['required_with:destination', 'integer', 'min:1'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireOneLanguage($validator, ['title']);
    }

    /**
     * @return array{title: array{ar: string, en: string}, media_id: ?int, destination: ?array{type: string, id: int}, city_id: ?int, starts_at: ?Carbon, ends_at: ?Carbon, sort: ?int}
     */
    public function banner(): array
    {
        $destination = $this->validated('destination');

        return [
            'title' => $this->translations('title'),
            'media_id' => $this->filled('media_id') ? (int) $this->validated('media_id') : null,
            'destination' => $destination ? ['type' => $destination['type'], 'id' => (int) $destination['id']] : null,
            'city_id' => $this->filled('city_id') ? (int) $this->validated('city_id') : null,
            'starts_at' => $this->filled('starts_at') ? Carbon::parse($this->validated('starts_at'))->utc() : null,
            'ends_at' => $this->filled('ends_at') ? Carbon::parse($this->validated('ends_at'))->utc() : null,
            'sort' => $this->filled('sort') ? (int) $this->validated('sort') : null,
        ];
    }
}
