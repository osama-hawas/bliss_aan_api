<?php

namespace App\Services\Accounts;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\ErrorCode;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\AccountStatusLog;
use App\Models\ActivityEvent;
use App\Models\Booking;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Client accounts in the dashboard (Req 53, Slice 15): list, detail, edit (D174), suspend / reactivate (D171, D173).
 * Suspension is account-level (`users.status`): every session is revoked and she signs in again after reactivation.
 * Nothing else changes on her requests, offers, bookings, chats or cart (D171).
 */
class ClientAccounts
{
    public const SORTS = ['created_at', '-created_at', 'name', '-name', 'bookings_count', '-bookings_count'];

    public const OPEN_REQUEST_STATUSES = [ServiceRequestStatus::AwaitingOffers, ServiceRequestStatus::OffersReceived];

    public const OPEN_BOOKING_STATUSES = [BookingStatus::Confirmed, BookingStatus::InProgress];

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly DeviceSessionService $sessions,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, city_id: ?int}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';

        return $this->query()
            ->with('city')
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$text}%")
                ->orWhere('email', 'like', "%{$text}%")
                ->when(ltrim($text, '+0') !== '', fn (Builder $p) => $p->orWhere('phone', 'like', '%'.ltrim($text, '+0').'%'))))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['city_id'], fn (Builder $q, int $city) => $q->where('city_id', $city))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * A client by id, with the list counters; any other user type is a 404.
     */
    public function find(int $id): User
    {
        return $this->query()->with('city')->findOrFail($id);
    }

    /**
     * @return array{requests: int, bookings: int}
     */
    public function openItems(User $client): array
    {
        return [
            'requests' => ServiceRequest::query()->where('client_id', $client->id)->whereIn('status', self::OPEN_REQUEST_STATUSES)->count(),
            'bookings' => Booking::query()->where('client_id', $client->id)->whereIn('status', self::OPEN_BOOKING_STATUSES)->count(),
        ];
    }

    /**
     * D174: name, email and city only, with a reason. The phone never changes here.
     *
     * @param  array{name: string, email: ?string, city_id: ?int}  $data
     */
    public function update(User $client, User $admin, array $data, string $reason): User
    {
        return DB::transaction(function () use ($client, $admin, $data, $reason) {
            $client = $this->lock($client);
            if ($client->status === UserStatus::Deleted) {
                throw new ApiException(ErrorCode::InvalidState); // S22: anonymised, nothing to edit
            }
            $old = $client->only(array_keys($data));
            $client->update($data);
            $changed = array_keys($client->getChanges());
            $changed = array_values(array_intersect(array_keys($data), $changed));

            $this->log($client, $admin, 'client_updated', $reason, null);
            $this->audit->log('client_updated', $admin, $client, $reason,
                array_intersect_key($old, array_flip($changed)), array_intersect_key($client->only(array_keys($data)), array_flip($changed)));

            return $client;
        });
    }

    /**
     * D173: suspends the account and revokes every session (and its push token) through the logout path.
     */
    public function suspend(User $client, User $admin, string $reason, ?string $internalNote): User
    {
        return DB::transaction(function () use ($client, $admin, $reason, $internalNote) {
            $client = $this->lock($client);
            if (in_array($client->status, [UserStatus::Suspended, UserStatus::Deleted], true)) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $from = $client->status;
            $client->update(['status' => UserStatus::Suspended, 'status_reason' => $reason]);
            $revoked = $this->sessions->revokeAll($client);

            $this->log($client, $admin, 'client_suspended', $reason, $internalNote);
            $this->activity->record($client, 'account_suspended', $from, UserStatus::Suspended, $admin, ActivityVisibility::Internal,
                array_filter(['sessions_revoked' => $revoked, 'internal_note' => $internalNote], fn ($v) => $v !== null));
            $this->audit->log('client_suspended', $admin, $client, $reason, ['status' => $from->value], ['status' => UserStatus::Suspended->value, 'sessions_revoked' => $revoked]);

            return $client;
        });
    }

    /**
     * Back to the status she had before the suspension (`active` or `pending_deletion`). No session comes back:
     * she signs in again (D173).
     */
    public function reactivate(User $client, User $admin, string $reason, ?string $internalNote): User
    {
        return DB::transaction(function () use ($client, $admin, $reason, $internalNote) {
            $client = $this->lock($client);
            if ($client->status !== UserStatus::Suspended) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $to = $this->statusBeforeSuspension($client);
            $client->update(['status' => $to, 'status_reason' => null]);

            $this->log($client, $admin, 'client_reactivated', $reason, $internalNote);
            $this->activity->record($client, 'account_reactivated', UserStatus::Suspended, $to, $admin, ActivityVisibility::Internal,
                array_filter(['internal_note' => $internalNote]));
            $this->audit->log('client_reactivated', $admin, $client, $reason, ['status' => UserStatus::Suspended->value], ['status' => $to->value]);

            return $client;
        });
    }

    private function statusBeforeSuspension(User $client): UserStatus
    {
        $from = ActivityEvent::query()
            ->whereMorphedTo('subject', $client)
            ->where('event', 'account_suspended')
            ->latest('id')
            ->value('from_status');

        $status = $from ? UserStatus::tryFrom($from) : null;

        return $status === null || $status === UserStatus::Suspended ? UserStatus::Active : $status;
    }

    /**
     * Kept local to this service (S14b writes its own staff log).
     */
    private function log(User $client, User $admin, string $action, ?string $reason, ?string $internalNote): void
    {
        AccountStatusLog::query()->create([
            'user_id' => $client->id,
            'action' => $action,
            'reason' => $reason,
            'internal_note' => $internalNote,
            'actor_id' => $admin->id,
        ]);
    }

    private function lock(User $client): User
    {
        return User::query()->where('type', UserType::Client)->lockForUpdate()->findOrFail($client->id);
    }

    private function query(): Builder
    {
        return User::query()
            ->where('type', UserType::Client)
            ->select('users.*')
            ->selectSub(ServiceRequest::query()->selectRaw('count(*)')->whereColumn('client_id', 'users.id'), 'requests_count')
            ->selectSub(Booking::query()->selectRaw('count(*)')->whereColumn('client_id', 'users.id'), 'bookings_count');
    }
}
