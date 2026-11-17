<?php

namespace App\Services\Payouts;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutRequestStatus;
use App\Enums\ProviderStatus;
use App\Exceptions\ApiException;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\ServiceRequests\IdempotencyRace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provider payout requests (slice-11-contract.md §5, D216, D221, D222, D225). Creating one debits the ledger at once
 * (`payout` entry), so the same money can never be requested twice.
 */
class PayoutRequests
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private readonly PayoutSettings $settings,
        private readonly ProviderEarnings $earnings,
        private readonly PayoutReferenceGenerator $references,
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
    ) {}

    public function list(Provider $provider, ?string $status, int $perPage): LengthAwarePaginator
    {
        return PayoutRequest::query()->where('provider_id', $provider->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(Provider $provider, int $id): PayoutRequest
    {
        return PayoutRequest::query()->where('provider_id', $provider->id)->whereKey($id)->first()
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @param  string  $amount  validated money string (> 0, ≤ 2 decimals)
     * @return array{0: PayoutRequest, 1: bool} the request and whether it was created now (false = replay)
     */
    public function create(User $user, string $amount, string $key): array
    {
        $provider = $user->provider ?? throw new ApiException(ErrorCode::NotFound);
        $cents = Cents::fromDecimal($amount);
        $fingerprint = hash('sha256', 'payout:'.$cents);

        return $this->retry->run(function () use ($user, $provider, $cents, $key, $fingerprint) {
            if ($existing = $this->replay($provider, $key, $fingerprint)) {
                return [$existing, false];
            }

            try {
                return [DB::transaction(fn () => $this->store($user, $provider, $cents, $key, $fingerprint)), true];
            } catch (IdempotencyRace) {
                return [$this->replay($provider, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
            }
        }, 'payout.request', ['provider_id' => $provider->id, 'idempotency_key' => $key]);
    }

    private function store(User $user, Provider $provider, int $cents, string $key, string $fingerprint): PayoutRequest
    {
        // Her provider row first: her payout writes run one at a time (staff processing uses the same order).
        $provider = Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
        if (PayoutRequest::query()->where('provider_id', $provider->id)->where('idempotency_key', $key)->lockForUpdate()->exists()) {
            throw new IdempotencyRace;
        }

        if ($provider->status !== ProviderStatus::Active) {
            throw new ApiException(ErrorCode::InvalidState); // D225
        }
        $this->settings->ensureConfigured();
        $account = PayoutAccount::query()->where('provider_id', $provider->id)->first()
            ?? throw new ApiException(ErrorCode::PayoutAccountRequired);
        if (PayoutRequest::query()->where('open_provider_id', $provider->id)->exists()) {
            throw new ApiException(ErrorCode::PayoutRequestOpen); // D221
        }
        $min = $this->settings->minCents();
        if ($min !== null && $cents < $min) {
            throw new ApiException(ErrorCode::ValidationFailed, errors: [
                'amount' => [__('payouts.below_minimum', ['amount' => Cents::toDecimal($min)])],
            ]);
        }
        if ($cents > $this->earnings->availableCents($provider->id)) {
            throw new ApiException(ErrorCode::InsufficientBalance);
        }

        $request = $this->insert([
            'provider_id' => $provider->id,
            'amount_cents' => $cents,
            'status' => PayoutRequestStatus::Requested,
            'holder_name' => $account->holder_name,
            'iban' => $account->iban,
            'iban_last4' => $account->iban_last4,
            'bank_name_key' => $account->bank_name_key,
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
            'open_provider_id' => $provider->id,
        ]);
        ProviderLedgerEntry::query()->create([
            'provider_id' => $provider->id,
            'type' => LedgerEntryType::Payout,
            'amount_cents' => -$cents,
            'payout_request_id' => $request->id,
            'available_at' => now(),
            'entry_key' => 'payout:'.$request->id,
        ]);
        $this->activity->record($request, 'payout_requested', null, PayoutRequestStatus::Requested, $user, ActivityVisibility::All,
            ['amount_cents' => $cents]);

        return $request;
    }

    private function insert(array $attributes): PayoutRequest
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return PayoutRequest::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'payout_requests_provider_id_idempotency_key_unique')) {
                    throw new IdempotencyRace;
                }
                if (str_contains($e->getMessage(), 'payout_requests_open_provider_id_unique')) {
                    throw new ApiException(ErrorCode::PayoutRequestOpen);
                }
                if (! str_contains($e->getMessage(), 'payout_requests_reference_unique')) {
                    throw $e;
                }
            }
        }

        Log::error('Payout reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }

    private function replay(Provider $provider, string $key, string $fingerprint): ?PayoutRequest
    {
        $existing = PayoutRequest::query()->where('provider_id', $provider->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }
}
