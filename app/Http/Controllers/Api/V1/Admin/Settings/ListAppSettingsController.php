<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Settings\AdminAppSettingResource;
use App\Services\Settings\SettingsEditor;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: every setting with its "not configured" state (D307).
 */
class ListAppSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsEditor $settings) {}

    public function __invoke()
    {
        return $this->success(AdminAppSettingResource::collection($this->settings->list()));
    }
}
