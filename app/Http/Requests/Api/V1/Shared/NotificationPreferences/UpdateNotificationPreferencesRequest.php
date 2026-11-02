<?php

namespace App\Http\Requests\Api\V1\Shared\NotificationPreferences;

use App\Enums\NotificationPreferenceKey;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * `preferences` is a partial map of key => enabled; only keys for the user's type are allowed.
     */
    public function rules(): array
    {
        $keys = array_map(
            fn (NotificationPreferenceKey $key) => $key->value,
            NotificationPreferenceKey::forUserType($this->user()->type),
        );

        $rules = ['preferences' => ['required', 'array:'.implode(',', $keys)]];

        foreach ($keys as $key) {
            $rules["preferences.{$key}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, bool>
     */
    public function changes(): array
    {
        return array_map(fn ($enabled) => (bool) $enabled, $this->validated('preferences'));
    }
}
