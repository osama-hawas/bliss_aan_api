<?php

namespace App\Http\Controllers\Api\V1\Provider\Notifications;

use App\Http\Controllers\Concerns\RespondsWithNotifications;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Notifications\ListNotificationsRequest;
use App\Traits\ApiResponse;

/**
 * Her notifications, newest first, with meta.unread_count (slice-8-contract.md §4).
 */
class ListNotificationsController extends Controller
{
    use ApiResponse, RespondsWithNotifications;

    public function __invoke(ListNotificationsRequest $request)
    {
        return $this->listNotifications($request);
    }
}
