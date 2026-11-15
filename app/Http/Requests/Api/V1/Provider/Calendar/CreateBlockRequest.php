<?php

namespace App\Http\Requests\Api\V1\Provider\Calendar;

use App\Support\RiyadhCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Exactly one form: a whole Riyadh `date`, or `starts_at` + `ends_at` (ISO-8601 with an offset, whole minutes).
 * Boundaries of §5.4: date from today, range not already over, at most 527 040 minutes.
 */
class CreateBlockRequest extends FormRequest
{
    public const MAX_MINUTES = 527040;

    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    private ?Carbon $startsAt = null;

    private ?Carbon $endsAt = null;

    protected function prepareForValidation(): void
    {
        if ($this->input('reason') === '') {
            $this->merge(['reason' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'string'],
            'ends_at' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->checkPeriod($v)];
    }

    private function checkPeriod(Validator $v): void
    {
        $hasDate = $this->filled('date');
        $hasStart = $this->filled('starts_at');
        $hasEnd = $this->filled('ends_at');

        if ($hasDate && ($hasStart || $hasEnd)) {
            $v->errors()->add('date', __('validation.custom.block.one_form'));

            return;
        }
        if (! $hasDate && ! $hasStart && ! $hasEnd) {
            $v->errors()->add('date', __('validation.custom.block.form_required'));

            return;
        }

        if ($hasDate) {
            $this->checkDate($v);

            return;
        }

        if (! $hasStart) {
            $v->errors()->add('starts_at', __('validation.custom.block.starts_at_required'));

            return;
        }
        if (! $hasEnd) {
            $v->errors()->add('ends_at', __('validation.custom.block.ends_at_required'));

            return;
        }

        $start = $this->instant($v, 'starts_at');
        $end = $this->instant($v, 'ends_at');
        if (! $start || ! $end) {
            return;
        }
        if ($end->lte($start)) {
            $v->errors()->add('ends_at', __('validation.custom.block.ends_after_start'));
        } elseif ($end->lte(now())) {
            $v->errors()->add('ends_at', __('validation.custom.block.ended'));
        } elseif ($start->diffInMinutes($end) > self::MAX_MINUTES) {
            $v->errors()->add('ends_at', __('validation.custom.block.too_long'));
        } else {
            [$this->startsAt, $this->endsAt] = [$start, $end];
        }
    }

    private function checkDate(Validator $v): void
    {
        $date = (string) $this->input('date');
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('!Y-m-d', $date) : false;
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            $v->errors()->add('date', __('validation.date_format', ['attribute' => 'date', 'format' => 'Y-m-d']));

            return;
        }
        if ($date < RiyadhCalendar::today()) {
            $v->errors()->add('date', __('validation.custom.block.date_past'));

            return;
        }

        [$this->startsAt, $this->endsAt] = [RiyadhCalendar::dayStart($date), RiyadhCalendar::dayEnd($date)];
    }

    private function instant(Validator $v, string $field): ?Carbon
    {
        $value = (string) $this->input($field);
        try {
            $instant = preg_match(self::ISO, $value) ? Carbon::parse($value)->utc() : null;
        } catch (Throwable) {
            $instant = null;
        }
        if (! $instant) {
            $v->errors()->add($field, __('validation.custom.block.iso'));

            return null;
        }
        if ($instant->second !== 0 || $instant->microsecond !== 0) {
            $v->errors()->add($field, __('validation.custom.block.whole_minutes'));

            return null;
        }

        return $instant;
    }

    /**
     * @return array{0: Carbon, 1: Carbon} UTC start and exclusive end
     */
    public function period(): array
    {
        return [$this->startsAt, $this->endsAt];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return $reason === null || $reason === '' ? null : $reason;
    }
}
