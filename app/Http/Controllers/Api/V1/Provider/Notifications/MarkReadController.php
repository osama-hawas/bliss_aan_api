<?php

namespace App\Http\Controllers\Api\V1\Provider\Notifications;

use App\Http\Controllers\Concerns\RespondsWithNotifications;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Idempotent; another user's notification is a 404.
 */
class MarkReadController extends Controller
{
    use ApiResponse, RespondsWithNotifications;

    public function __invoke(Request $request, string $notification)
    {
        return $this->markNotificationRead($request, $notification);
    }
}
