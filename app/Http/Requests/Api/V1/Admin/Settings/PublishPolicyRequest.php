<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use App\Enums\PolicyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new policy version (D310): never in the past, values = flat object of scalars / null.
 */
class PublishPolicyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PolicyType::class)],
            'effective_from' => ['required', 'date', 'after_or_equal:'.now()->subMinute()->toIso8601String()],
            'content' => ['nullable', 'array'],
            'content.ar' => ['nullable', 'string', 'max:30000'],
            'content.en' => ['nullable', 'string', 'max:30000'],
            'values' => ['present', 'array', 'max:30'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $values = $this->input('values');
            if (! is_array($values)) {
                return;
            }
            if ($values !== [] && array_is_list($values)) {
                $v->errors()->add('values', __('content.policy_values_invalid'));

                return;
            }
            foreach ($values as $key => $value) {
                if (! preg_match('/^[a-z][a-z0-9_]{0,49}$/', (string) $key) || ! (is_scalar($value) || $value === null) || (is_string($value) && mb_strlen($value) > 500)) {
                    $v->errors()->add("values.{$key}", __('content.policy_values_invalid'));
                }
            }
        });
    }

    public function type(): PolicyType
    {
        return PolicyType::from($this->validated('type'));
    }

    public function effectiveFrom(): Carbon
    {
        return Carbon::parse($this->validated('effective_from'))->utc();
    }

    /**
     * @return array{ar: string, en: string}|null
     */
    public function content(): ?array
    {
        $content = $this->validated('content');
        if (! $content) {
            return null;
        }
        $clean = ['ar' => trim((string) ($content['ar'] ?? '')), 'en' => trim((string) ($content['en'] ?? ''))];

        return $clean['ar'] === '' && $clean['en'] === '' ? null : $clean;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function values(): array
    {
        return (array) $this->input('values', []);
    }
}
