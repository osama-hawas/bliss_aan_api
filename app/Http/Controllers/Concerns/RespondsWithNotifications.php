<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Api\V1\Shared\Notifications\ListNotificationsRequest;
use App\Http\Resources\Api\V1\Shared\Notifications\NotificationResource;
use App\Services\Notifications\NotificationInbox;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification actions are the same for clients and providers (slice-8-contract.md §4); each audience keeps its
 * own controllers and routes. Every lookup is scoped to the signed-in user (404 otherwise). Uses ApiResponse.
 */
trait RespondsWithNotifications
{
    protected function listNotifications(ListNotificationsRequest $request): JsonResponse
    {
        $inbox = app(NotificationInbox::class);
        $page = $inbox->list($request->user(), $request->readFilter(), Pagination::perPage($request));

        return $this->success(NotificationResource::collection($page), null, 200, ['unread_count' => $inbox->unreadCount($request->user())]);
    }

    protected function notificationsUnreadCount(Request $request): JsonResponse
    {
        return $this->success(['unread_count' => app(NotificationInbox::class)->unreadCount($request->user())]);
    }

    protected function markNotificationRead(Request $request, string $id): JsonResponse
    {
        $row = app(NotificationInbox::class)->markRead($request->user(), $id);

        return $this->success(new NotificationResource($row), null, 200, ['unread_count' => app(NotificationInbox::class)->unreadCount($request->user())]);
    }

    protected function markAllNotificationsRead(Request $request): JsonResponse
    {
        $inbox = app(NotificationInbox::class);
        $marked = $inbox->markAllRead($request->user());

        return $this->success(['marked' => $marked, 'unread_count' => $inbox->unreadCount($request->user())]);
    }
}
