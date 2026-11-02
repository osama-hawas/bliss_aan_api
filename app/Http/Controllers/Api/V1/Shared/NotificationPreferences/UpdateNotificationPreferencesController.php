<?php

namespace App\Http\Controllers\Api\V1\Shared\NotificationPreferences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\NotificationPreferences\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Api\V1\Shared\NotificationPreferences\NotificationPreferenceResource;
use App\Services\Notifications\NotificationPreferenceService;
use App\Traits\ApiResponse;

class UpdateNotificationPreferencesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function __invoke(UpdateNotificationPreferencesRequest $request)
    {
        $result = $this->preferences->update($request->user(), $request->changes());

        return $this->success(NotificationPreferenceResource::collection($result), __('Saved.'));
    }
}
