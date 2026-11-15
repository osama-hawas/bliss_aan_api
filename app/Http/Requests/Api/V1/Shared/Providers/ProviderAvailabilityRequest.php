<?php

namespace App\Http\Requests\Api\V1\Shared\Providers;

use App\Http\Requests\Concerns\ValidatesDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Public availability range: `from` today or later in Riyadh (§7).
 */
class ProviderAvailabilityRequest extends FormRequest
{
    use ValidatesDateRange;

    public function rules(): array
    {
        return $this->rangeRules();
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->checkRange($v, fromToday: true)];
    }
}
