<?php

namespace App\Services\Bookings;

use App\Enums\ActivityVisibility;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Enums\ProviderStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\Availability\ProviderAvailability;
use App\Services\Notifications\Notifier;
use App\Services\ServiceRequests\IdempotencyRace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The client accepts an offer → the booking is confirmed at once, no payment step (slice-5c-contract.md §3, D4,
 * D107–D113). Replay first (same key + same offer → the original booking; same key + another offer → 409
 * DUPLICATE_REQUEST). One transaction, locks in the D112 order: client → request → its offers → provider → recipients;
 * then every rule is re-checked on the locked rows. A deadlock re-runs the whole attempt (D98). The unique keys on
 * `bookings.service_request_id` / `offer_id` are the last guard (→ 409 ALREADY_BOOKED). The wallet (D106) is not
 * involved.
 */
class OfferAcceptor
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    private const REFERENCE_INDEX = 'bookings_reference_unique';

    private const IDEMPOTENCY_INDEX = 'bookings_client_id_idempotency_key_unique';

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
        private readonly ProviderAvailability $availability,
        private readonly BookingReferenceGenerator $references,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return array{0: Booking, 1: bool} the booking and whether it was created now (false = replay)
     */
    public function accept(User $client, Offer $offer, string $key): array
    {
        $fingerprint = hash('sha256', 'offer:'.$offer->id);

        return $this->retry->run(function () use ($client, $offer, $key, $fingerprint) {
            if ($existing = $this->replay($client, $key, $fingerprint)) {
                return [$existing, false];
            }

            try {
                return [DB::transaction(fn () => $this->store($client, $offer, $key, $fingerprint)), true];
            } catch (IdempotencyRace) {
                return [$this->replay($client, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
            }
        }, 'offer.accept', ['user_id' => $client->id, 'offer_id' => $offer->id, 'idempotency_key' => $key]);
    }

    private function store(User $client, Offer $offer, string $key, string $fingerprint): Booking
    {
        // 1. Client: her accepts run one at a time; the key is looked up again with a locking read.
        User::query()->whereKey($client->id)->lockForUpdate()->first(['id']);
        if (Booking::query()->where('client_id', $client->id)->where('idempotency_key', $key)->lockForUpdate()->exists()) {
            throw new IdempotencyRace;
        }

        // 2. Request, 3. its offers, 4. provider, then the recipient rows.
        $request = ServiceRequest::query()->whereKey($offer->service_request_id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
        $offers = Offer::query()->where('service_request_id', $request->id)->orderBy('id')->lockForUpdate()->get();
        $offer = $offers->firstWhere('id', $offer->id) ?? throw new ApiException(ErrorCode::NotFound);
        $provider = Provider::query()->whereKey($offer->provider_id)->lockForUpdate()->firstOrFail();
        $recipients = ServiceRequestRecipient::query()->where('service_request_id', $request->id)->orderBy('id')->lockForUpdate()->get();

        // Re-validate on the locked rows (contract §3, order matters).
        if ($request->status === ServiceRequestStatus::Confirmed || Booking::query()->where('service_request_id', $request->id)->exists()) {
            throw new ApiException(ErrorCode::AlreadyBooked);
        }
        if (! $request->status->isOpenForResponses() || ! in_array($offer->status, [OfferStatus::Active, OfferStatus::Expired], true)) {
            throw new ApiException(ErrorCode::InvalidState);
        }
        if (now()->gte($request->starts_at)) {
            throw new ApiException(ErrorCode::InvalidState); // D111
        }
        if ($offer->effectiveStatus() !== OfferStatus::Active) {
            throw new ApiException(ErrorCode::OfferExpired); // D111
        }
        $provider->loadMissing('user');
        if ($provider->status !== ProviderStatus::Active || $provider->user?->status !== UserStatus::Active) {
            throw new ApiException(ErrorCode::InvalidState);
        }
        if ($recipients->firstWhere('provider_id', $provider->id)?->status !== RecipientStatus::OfferSent) {
            throw new ApiException(ErrorCode::InvalidState);
        }
        if ($this->availability->isBusy($provider->id, $request->starts_at, $request->ends_at)) {
            throw new ApiException(ErrorCode::SlotUnavailable); // D109, D110
        }

        $now = now();
        $booking = $this->insert([
            'service_request_id' => $request->id,
            'offer_id' => $offer->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'service_id' => $request->service_id,
            'execution_place' => $request->execution_place,
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'address' => $request->address,
            'title' => $offer->title,
            'total' => $offer->total,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired, // D113
            'policy_version_id' => $offer->policy_version_id,
            'confirmed_at' => $now,
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
        ]);

        // D4: the accepted offer is selected, every other offer still in play is not selected.
        $offer->update(['status' => OfferStatus::Selected]);
        // D134: providers whose offer was still live are told it was not selected.
        $liveOthers = $offers->where('id', '!=', $offer->id)->filter(fn (Offer $o) => $o->effectiveStatus() === OfferStatus::Active)->values();
        $offers->where('id', '!=', $offer->id)
            ->filter(fn (Offer $o) => in_array($o->status, [OfferStatus::Active, OfferStatus::Expired], true))
            ->each(fn (Offer $o) => $o->update(['status' => OfferStatus::NotSelected, 'closed_at' => $now]));
        // Other providers' copies close (they lose attachment access, D52); the winner stays offer_sent.
        $recipients->where('provider_id', '!=', $provider->id)
            ->filter(fn (ServiceRequestRecipient $r) => $r->status->isActive())
            ->each(fn (ServiceRequestRecipient $r) => $r->update(['status' => RecipientStatus::Closed, 'closed_at' => $now]));

        $from = $request->status;
        $request->update(['status' => ServiceRequestStatus::Confirmed]);
        $this->activity->record($request, 'booking_confirmed', $from, ServiceRequestStatus::Confirmed, $client, ActivityVisibility::All, ['booking_id' => $booking->id, 'offer_id' => $offer->id, 'provider_id' => $provider->id]);
        // E8 / E9 (D133, D134), after commit.
        $this->notifier->send((int) $provider->user_id, NotificationEvent::OfferAccepted,
            Notifier::bookingParams($booking, $request, Notifier::firstName($client->name)), NotificationTargetType::Booking, $booking->id,
            'offer_accepted:'.$booking->id, $client);
        $losers = Provider::query()->whereKey($liveOthers->pluck('provider_id')->all())->pluck('user_id', 'id');
        foreach ($liveOthers as $other) {
            $this->notifier->send((int) $losers[$other->provider_id], NotificationEvent::OfferNotSelected, Notifier::requestParams($request, null),
                NotificationTargetType::Request, $request->id, 'offer_not_selected:'.$other->id, $client);
        }

        return $booking;
    }

    private function insert(array $attributes): Booking
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return Booking::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                    throw new IdempotencyRace;
                }
                if (str_contains($e->getMessage(), 'bookings_service_request_id_unique') || str_contains($e->getMessage(), 'bookings_offer_id_unique')) {
                    throw new ApiException(ErrorCode::AlreadyBooked);
                }
                if (! str_contains($e->getMessage(), self::REFERENCE_INDEX)) {
                    throw $e;
                }
            }
        }

        Log::error('Booking reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }

    private function replay(User $client, string $key, string $fingerprint): ?Booking
    {
        $existing = Booking::query()->where('client_id', $client->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }
}
