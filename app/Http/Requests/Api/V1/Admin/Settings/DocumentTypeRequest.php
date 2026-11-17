<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use App\Enums\DocumentAppliesTo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A provider document type (D309). Empty mimes / size = the `uploads.provider_document` limits apply.
 */
class DocumentTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:100'],
            'name.en' => ['required', 'string', 'max:100'],
            'applies_to' => ['required', Rule::enum(DocumentAppliesTo::class)],
            'is_required' => ['required', 'boolean'],
            'allowed_mimes' => ['nullable', 'array', 'max:10'],
            'allowed_mimes.*' => ['string', 'distinct', 'regex:/^[a-z0-9]{1,10}$/'],
            'max_size_kb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
    }

    /**
     * @return array{name: array{ar: string, en: string}, applies_to: DocumentAppliesTo, is_required: bool, allowed_mimes: ?array, max_size_kb: ?int, sort: ?int}
     */
    public function documentType(): array
    {
        $mimes = $this->validated('allowed_mimes');

        return [
            'name' => ['ar' => trim($this->validated('name.ar')), 'en' => trim($this->validated('name.en'))],
            'applies_to' => DocumentAppliesTo::from($this->validated('applies_to')),
            'is_required' => (bool) $this->validated('is_required'),
            'allowed_mimes' => $mimes ? array_values($mimes) : null,
            'max_size_kb' => $this->filled('max_size_kb') ? (int) $this->validated('max_size_kb') : null,
            'sort' => $this->filled('sort') ? (int) $this->validated('sort') : null,
        ];
    }
}
