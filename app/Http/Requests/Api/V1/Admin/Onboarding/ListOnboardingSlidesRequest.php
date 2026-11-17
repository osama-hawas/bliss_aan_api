<?php

namespace App\Http\Requests\Api\V1\Admin\Onboarding;

use App\Enums\Audience;
use App\Enums\ContentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `filter[app]=client|provider`, `filter[status]=visible|hidden` (Slice 25, D362).
 */
class ListOnboardingSlidesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.app' => ['nullable', Rule::enum(Audience::class)],
            'filter.status' => ['nullable', Rule::enum(ContentStatus::class)],
        ];
    }

    public function app(): ?Audience
    {
        return $this->filled('filter.app') ? Audience::from($this->validated('filter.app')) : null;
    }

    public function status(): ?string
    {
        return $this->validated('filter.status');
    }
}
