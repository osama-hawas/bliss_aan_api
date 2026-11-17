<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ScheduleBroadcastRequest extends FormRequest
{
    public function rules(): array
    {
        return ['scheduled_at' => ['required', 'date', 'after:now', 'before:+1 year']];
    }

    public function scheduledAt(): Carbon
    {
        return Carbon::parse($this->validated('scheduled_at'))->utc();
    }
}
