<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\UpdateAppSettingRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminAppSettingResource;
use App\Services\Settings\SettingsEditor;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: sets a value, or `null` = not configured, with a reason (D307).
 */
class UpdateAppSettingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsEditor $settings) {}

    public function __invoke(UpdateAppSettingRequest $request, string $key)
    {
        $this->settings->update($key, $request->input('value'), $request->user(), $request->validated('reason'));
        $row = collect($this->settings->list())->firstWhere('key', $key);

        return $this->success(new AdminAppSettingResource($row), __('content.messages.setting_saved'));
    }
}
