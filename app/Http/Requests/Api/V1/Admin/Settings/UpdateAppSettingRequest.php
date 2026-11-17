<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `value` is checked against the key's kind by SettingsEditor; `null` = not configured (D307).
 */
class UpdateAppSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => ['present'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
