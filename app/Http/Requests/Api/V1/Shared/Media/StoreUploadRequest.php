<?php

namespace App\Http\Requests\Api\V1\Shared\Media;

use App\Enums\UploadContext;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUploadRequest extends FormRequest
{
    /**
     * Each context is limited to the user types that use it (e.g. documents → providers).
     */
    public function authorize(): bool
    {
        $context = $this->context();

        return $context === null || in_array($this->user()->type, $context->allowedUserTypes(), true);
    }

    /**
     * Allowed types and size come from app_settings `uploads.<context>`.
     * Not specified yet → 409 NOT_CONFIGURED (limits are an open business decision).
     */
    public function rules(): array
    {
        $rules = [
            'context' => ['required', Rule::enum(UploadContext::class)],
            'file' => ['required', 'file'],
        ];

        if ($context = $this->context()) {
            $limits = app(AppSettings::class)->required($context->settingKey());
            $rules['file'][] = 'mimes:'.implode(',', $limits['mimes']);
            $rules['file'][] = 'max:'.$limits['max_kb'];
        }

        return $rules;
    }

    public function context(): ?UploadContext
    {
        return is_string($this->input('context')) ? UploadContext::tryFrom($this->input('context')) : null;
    }
}
