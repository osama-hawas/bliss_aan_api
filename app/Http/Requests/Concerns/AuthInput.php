<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DeviceApp;
use App\Rules\SaudiMobile;
use App\Support\Phone;
use Illuminate\Validation\Rule;

/**
 * Shared input for the auth endpoints: E.164 phone, the calling app and the device info
 * stored on the user_devices session row.
 */
trait AuthInput
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone')) && $phone = Phone::normalizeSaudiMobile($this->input('phone'))) {
            $this->merge(['phone' => $phone]);
        }
    }

    protected function phoneRules(): array
    {
        return ['required', 'string', new SaudiMobile];
    }

    protected function codeRules(): array
    {
        return ['required', 'string', 'regex:/^\d+$/', 'max:10'];
    }

    /**
     * Every app signs in with phone + OTP, including the dashboard (Q9).
     */
    protected function appRules(): array
    {
        return ['required', Rule::enum(DeviceApp::class)];
    }

    protected function deviceRules(): array
    {
        return [
            'device' => ['sometimes', 'array'],
            'device.platform' => ['nullable', Rule::in(['ios', 'android', 'web'])],
            'device.device_name' => ['nullable', 'string', 'max:255'],
            'device.fcm_token' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function deviceApp(): DeviceApp
    {
        return DeviceApp::from($this->validated('app'));
    }

    /**
     * @return array{platform?: ?string, device_name?: ?string, fcm_token?: ?string}
     */
    public function deviceInfo(): array
    {
        return $this->validated('device') ?? [];
    }
}
