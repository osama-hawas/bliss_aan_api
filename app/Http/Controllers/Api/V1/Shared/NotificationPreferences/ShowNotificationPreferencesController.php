<?php

namespace App\Http\Controllers\Api\V1\Shared\NotificationPreferences;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\NotificationPreferences\NotificationPreferenceResource;
use App\Services\Notifications\NotificationPreferenceService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShowNotificationPreferencesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function __invoke(Request $request)
    {
        return $this->success(NotificationPreferenceResource::collection($this->preferences->forUser($request->user())));
    }
}
