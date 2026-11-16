<?php

namespace App\Services\Notifications;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Her own notification list (slice-8-contract.md §4, D137). Every lookup is scoped to the signed-in user: another
 * user's id is a 404.
 */
class NotificationInbox
{
    public function list(User $user, ?bool $read, int $perPage): LengthAwarePaginator
    {
        return $user->notifications()->getQuery()
            ->when($read === true, fn ($q) => $q->whereNotNull('read_at'))
            ->when($read === false, fn ($q) => $q->whereNull('read_at'))
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function find(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->first() ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * Idempotent: an already read row keeps its first read time.
     */
    public function markRead(User $user, string $id): DatabaseNotification
    {
        $row = $this->find($user, $id);
        if ($row->read_at === null) {
            $row->forceFill(['read_at' => now()])->save();
        }

        return $row;
    }

    /**
     * Rows created up to now (D137); a row arriving during the call stays unread.
     *
     * @return int how many rows were marked
     */
    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()->getQuery()->where('created_at', '<=', now())->update(['read_at' => now()]);
    }
}
