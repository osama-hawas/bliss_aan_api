<?php

namespace App\Services\Account;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\DeletionReason;
use App\Enums\DeletionRequestStatus;
use App\Enums\ErrorCode;
use App\Enums\OfferStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\AccountDeletionRequest;
use App\Models\Booking;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Account deletion requests (Req 06, Slice 12a). D150: the request never changes `users.status` and restricts
 * nothing. D151: one pending request per account, she may withdraw it. D155: nobody processes requests — no method
 * here moves one to `processed` or `rejected`.
 */
class AccountDeletion
{
    private const OPEN_REQUEST_STATUSES = [ServiceRequestStatus::AwaitingOffers, ServiceRequestStatus::OffersReceived];

    private const ACTIVE_BOOKING_STATUSES = [BookingStatus::Confirmed, BookingStatus::InProgress];

    public function __construct(private readonly ActivityRecorder $activity) {}

    public function pending(User $user): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()->where('open_user_id', $user->id)->first();
    }

    /**
     * Her latest request, pending or closed.
     */
    public function latest(User $user): ?AccountDeletionRequest
    {
        return $user->deletionRequests()->latest('id')->first();
    }

    /**
     * Live counts of what keeps going whatever she asks (Req 06: active bookings are never hidden). Shown, never blocking.
     *
     * @return array<string, int>
     */
    public function obligations(User $user): array
    {
        if ($user->type === UserType::Client) {
            return [
                'open_requests' => ServiceRequest::query()->where('client_id', $user->id)
                    ->whereIn('status', self::OPEN_REQUEST_STATUSES)->count(),
                'active_bookings' => Booking::query()->where('client_id', $user->id)
                    ->whereIn('status', self::ACTIVE_BOOKING_STATUSES)->count(),
            ];
        }

        $providerId = $user->provider?->id;
        if ($providerId === null) {
            return ['active_bookings' => 0, 'open_inbox' => 0];
        }

        return [
            'active_bookings' => Booking::query()->where('provider_id', $providerId)
                ->whereIn('status', self::ACTIVE_BOOKING_STATUSES)->count(),
            // Her `new` recipient rows, and `offer_sent` ones only while her offer is still live (active, not past its
            // validity), on requests still open.
            'open_inbox' => ServiceRequestRecipient::query()->where('provider_id', $providerId)
                ->whereHas('serviceRequest', fn ($q) => $q->whereIn('status', self::OPEN_REQUEST_STATUSES))
                ->where(fn ($q) => $q->where('status', RecipientStatus::New)
                    ->orWhere(fn ($q) => $q->where('status', RecipientStatus::OfferSent)
                        ->whereExists(fn ($offer) => $offer->selectRaw('1')->from('offers')
                            ->whereColumn('offers.service_request_id', 'service_request_recipients.service_request_id')
                            ->where('offers.provider_id', $providerId)
                            ->where('offers.status', OfferStatus::Active)
                            ->where('offers.valid_until', '>', now()))))
                ->count(),
        ];
    }

    /**
     * One pending request per account: her `users` row lock serializes concurrent sends, the unique `open_user_id`
     * is the final guard. Both answer 409 DELETION_PENDING.
     */
    public function request(User $user, DeletionReason $reason, ?string $details): AccountDeletionRequest
    {
        $details = trim((string) $details);

        try {
            return DB::transaction(function () use ($user, $reason, $details) {
                User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);
                if (AccountDeletionRequest::query()->where('open_user_id', $user->id)->exists()) {
                    throw new ApiException(ErrorCode::DeletionPending);
                }

                $request = AccountDeletionRequest::query()->create([
                    'user_id' => $user->id,
                    'open_user_id' => $user->id,
                    'reason_code' => $reason,
                    'details' => $details === '' ? null : $details,
                    'status' => DeletionRequestStatus::Pending,
                    'user_type' => $user->type,
                ]);
                $this->activity->record($request, 'deletion_requested', null, DeletionRequestStatus::Pending, $user, ActivityVisibility::Internal);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::DeletionPending);
        }
    }

    public function withdraw(User $user): AccountDeletionRequest
    {
        return DB::transaction(function () use ($user) {
            $request = AccountDeletionRequest::query()->where('open_user_id', $user->id)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::InvalidState);

            $request->update(['status' => DeletionRequestStatus::Withdrawn, 'open_user_id' => null, 'withdrawn_at' => now()]);
            $this->activity->record($request, 'deletion_withdrawn', DeletionRequestStatus::Pending, DeletionRequestStatus::Withdrawn, $user, ActivityVisibility::Internal);

            return $request;
        });
    }
}
