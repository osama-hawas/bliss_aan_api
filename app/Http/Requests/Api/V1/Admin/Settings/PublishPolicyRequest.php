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
    private const ZONE = 'Asia/Riyadh';

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PolicyType::class)],
            'effective_from' => ['required', 'date'],
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
            if (! $v->errors()->has('effective_from') && $this->filled('effective_from')) {
                // A date-only value is the start of that Riyadh day and may be today (review M5); a time must not be past.
                $earliest = $this->isDateOnly()
                    ? Carbon::createFromFormat('Y-m-d H:i:s', now(self::ZONE)->format('Y-m-d').' 00:00:00', self::ZONE)
                    : now()->subMinute();
                if ($this->effectiveFrom()->lt($earliest)) {
                    $v->errors()->add('effective_from', __('validation.after_or_equal', ['attribute' => 'effective_from', 'date' => $earliest->toDateString()]));
                }
            }
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
        $value = (string) $this->input('effective_from');

        // Every field given explicitly: the result never depends on Carbon's default zone or a frozen test clock.
        return ($this->isDateOnly() ? Carbon::createFromFormat('Y-m-d H:i:s', $value.' 00:00:00', self::ZONE) : Carbon::parse($value))->utc();
    }

    private function isDateOnly(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->input('effective_from')) === 1;
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
