<?php

namespace App\Services\Offers;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Enums\PolicyType;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use App\Services\ServiceRequests\IdempotencyRace;
use App\Services\Settings\PolicyResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sends an offer (slice-5b-contract.md §3). Replay first: same Idempotency-Key + same body → the original offer,
 * same key + another body → 409 DUPLICATE_REQUEST. Transaction: lock the request → her recipient row → re-check the
 * key with a locking read → re-check the states → supersede her live offer (D6) → insert offer + items → recipient
 * `offer_sent` → request `offers_received` → history. A deadlock re-runs the whole attempt (D98).
 */
class OfferSender
{
    public const VALIDITY_DAYS = [3, 7, 14]; // D5

    public const MAX_TOTAL_CENTS = 99_999_999;

    private const MAX_REFERENCE_ATTEMPTS = 5;

    private const REFERENCE_INDEX = 'offers_reference_unique';

    private const IDEMPOTENCY_INDEX = 'offers_provider_id_idempotency_key_unique';

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
        private readonly OfferReferenceGenerator $references,
        private readonly PolicyResolver $policies,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array{title: string, items: list<array{description: string, quantity: int|string, unit_price: string|int|float}>, validity_days: int|string, notes?: ?string}  $data
     * @return array{0: Offer, 1: bool} the offer and whether it was created now (false = replay)
     */
    public function send(Provider $provider, ServiceRequest $request, string $key, array $data): array
    {
        $lines = $this->lines($data['items']);
        $total = array_sum(array_column($lines, 'line_cents'));
        if ($total <= 0 || $total > self::MAX_TOTAL_CENTS) {
            throw ValidationException::withMessages(['items' => __('validation.offer_total')]);
        }
        $fingerprint = $this->fingerprint($request->id, $data, $lines);

        return $this->retry->run(function () use ($provider, $request, $key, $data, $lines, $total, $fingerprint) {
            if ($existing = $this->replay($provider, $key, $fingerprint)) {
                return [$existing, false];
            }

            try {
                return [DB::transaction(fn () => $this->store($provider, $request, $key, $fingerprint, $data, $lines, $total)), true];
            } catch (IdempotencyRace) {
                return [$this->replay($provider, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
            }
        }, 'offer.send', ['provider_id' => $provider->id, 'service_request_id' => $request->id, 'idempotency_key' => $key]);
    }

    private function store(Provider $provider, ServiceRequest $request, string $key, string $fingerprint, array $data, array $lines, int $total): Offer
    {
        $request = ServiceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        $recipient = ServiceRequestRecipient::query()
            ->where('service_request_id', $request->id)->where('provider_id', $provider->id)
            ->lockForUpdate()->firstOrFail();
        if (Offer::query()->where('provider_id', $provider->id)->where('idempotency_key', $key)->lockForUpdate()->exists()) {
            throw new IdempotencyRace;
        }
        if (! $recipient->status->isActive() || ! $request->status->isOpenForResponses()) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        // D6: never edited; her previous live offer is replaced. Expired ones keep their stored status.
        Offer::query()->where('service_request_id', $request->id)->where('provider_id', $provider->id)
            ->where('status', OfferStatus::Active)->lockForUpdate()->get()
            ->each(fn (Offer $o) => $o->update(['status' => $o->valid_until->lte(now()) ? OfferStatus::Expired : OfferStatus::Superseded]));

        $now = now();
        $days = (int) $data['validity_days'];
        $offer = $this->insert([
            'service_request_id' => $request->id,
            'provider_id' => $provider->id,
            'title' => trim($data['title']),
            'total' => $this->decimal($total),
            'validity_days' => $days,
            'valid_until' => $now->copy()->addDays($days),
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'is_quick' => false,
            'status' => OfferStatus::Active,
            'policy_version_id' => $this->policies->current(PolicyType::Cancellation)?->id,
            'sent_at' => $now,
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
        ]);
        foreach ($lines as $i => $line) {
            $offer->items()->create([
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $this->decimal($line['unit_cents']),
                'line_total' => $this->decimal($line['line_cents']),
                'sort' => $i,
            ]);
        }

        if ($recipient->status === RecipientStatus::New) {
            $recipient->update(['status' => RecipientStatus::OfferSent]);
        }
        $from = $request->status;
        if ($from === ServiceRequestStatus::AwaitingOffers) {
            $request->update(['status' => ServiceRequestStatus::OffersReceived]);
        }
        $this->activity->record($request, 'offer_sent', $from, $request->status, $provider->user, ActivityVisibility::All, ['offer_id' => $offer->id, 'provider_id' => $provider->id]);
        // E4 (D133): the client, after commit.
        $this->notifier->send((int) $request->client_id, NotificationEvent::OfferReceived, Notifier::requestParams($request, $provider->display_name),
            NotificationTargetType::Offer, $offer->id, 'offer_received:'.$offer->id, $provider->user_id);

        return $offer;
    }

    private function insert(array $attributes): Offer
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return Offer::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                    throw new IdempotencyRace;
                }
                if (! str_contains($e->getMessage(), self::REFERENCE_INDEX)) {
                    throw $e;
                }
            }
        }

        Log::error('Offer reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }

    private function replay(Provider $provider, string $key, string $fingerprint): ?Offer
    {
        $existing = Offer::query()->where('provider_id', $provider->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }

    /**
     * Integer cents only (never floats).
     *
     * @return list<array{description: string, quantity: int, unit_cents: int, line_cents: int}>
     */
    private function lines(array $items): array
    {
        return array_values(array_map(function (array $item) {
            [$whole, $fraction] = array_pad(explode('.', (string) $item['unit_price'], 2), 2, '');
            $unit = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
            $quantity = (int) $item['quantity'];

            return ['description' => trim($item['description']), 'quantity' => $quantity, 'unit_cents' => $unit, 'line_cents' => $unit * $quantity];
        }, $items));
    }

    private function fingerprint(int $requestId, array $data, array $lines): string
    {
        return hash('sha256', json_encode([
            'service_request_id' => $requestId,
            'title' => trim($data['title']),
            'items' => array_map(fn (array $l) => [$l['description'], $l['quantity'], $l['unit_cents']], $lines),
            'validity_days' => (int) $data['validity_days'],
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : '',
        ]));
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
