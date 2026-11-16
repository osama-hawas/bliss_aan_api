<?php

namespace App\Http\Controllers\Api\V1\Provider\Notifications;

use App\Http\Controllers\Concerns\RespondsWithNotifications;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class MarkAllReadController extends Controller
{
    use ApiResponse, RespondsWithNotifications;

    public function __invoke(Request $request)
    {
        return $this->markAllNotificationsRead($request);
    }
}
