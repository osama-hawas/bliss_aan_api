<?php

namespace App\Services\Payouts;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutBlock;
use App\Enums\PayoutRequestStatus;
use App\Enums\ProviderStatus;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Balances are sums over the append-only ledger (slice-11-contract.md §4.3); nothing is stored.
 */
class ProviderEarnings
{
    public function __construct(private readonly PayoutSettings $settings) {}

    /**
     * @return array{available: int, pending: int, gross: int, commission: int, net: int, in_payout: int, paid_out: int, next_available_at: ?Carbon}
     */
    public function balances(int $providerId): array
    {
        $now = now();
        $booking = "'".implode("','", LedgerEntryType::bookingTypes())."'";
        $row = ProviderLedgerEntry::query()->where('provider_id', $providerId)->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN available_at <= ? THEN amount_cents ELSE 0 END), 0) AS available', [$now])
            ->selectRaw("COALESCE(SUM(CASE WHEN available_at > ? AND type IN ($booking) THEN amount_cents ELSE 0 END), 0) AS pending", [$now])
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ($booking) THEN gross_cents ELSE 0 END), 0) AS gross")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ($booking) THEN commission_cents ELSE 0 END), 0) AS commission")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ($booking) THEN amount_cents ELSE 0 END), 0) AS net")
            ->selectRaw('MIN(CASE WHEN available_at > ? AND type = ? THEN available_at END) AS next_available_at', [$now, LedgerEntryType::Earning->value])
            ->first();

        $requests = PayoutRequest::query()->where('provider_id', $providerId)->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount_cents ELSE 0 END), 0) AS in_payout', [PayoutRequestStatus::Requested->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount_cents ELSE 0 END), 0) AS paid_out', [PayoutRequestStatus::Paid->value])
            ->first();

        return [
            'available' => (int) $row->available,
            'pending' => (int) $row->pending,
            'gross' => (int) $row->gross,
            'commission' => (int) $row->commission,
            'net' => (int) $row->net,
            'in_payout' => (int) $requests->in_payout,
            'paid_out' => (int) $requests->paid_out,
            'next_available_at' => $row->next_available_at ? Carbon::parse($row->next_available_at, 'UTC') : null,
        ];
    }

    public function availableCents(int $providerId): int
    {
        return (int) ProviderLedgerEntry::query()->where('provider_id', $providerId)
            ->where('available_at', '<=', now())->sum('amount_cents');
    }

    /**
     * Everything `GET provider/earnings` shows. 409 NOT_CONFIGURED while a setting is not specified (D215).
     *
     * @return array<string, mixed>
     */
    public function summary(Provider $provider): array
    {
        $this->settings->ensureConfigured();
        $balances = $this->balances($provider->id);
        $account = PayoutAccount::query()->where('provider_id', $provider->id)->first();
        $open = PayoutRequest::query()->where('open_provider_id', $provider->id)->first();
        $block = $this->block($provider, $balances['available'], $account, $open);

        return [
            'commission_bps' => $this->settings->commissionBps(),
            'hold_days' => $this->settings->holdDays(),
            'min_cents' => $this->settings->minCents(),
            'balances' => $balances,
            'account' => $account,
            'open_request' => $open,
            'block' => $block,
        ];
    }

    /**
     * Why she cannot request a payout now, in the contract's order (§5), or null.
     */
    public function block(Provider $provider, int $availableCents, ?PayoutAccount $account, ?PayoutRequest $open): ?PayoutBlock
    {
        return match (true) {
            $provider->status !== ProviderStatus::Active => PayoutBlock::ProviderInactive, // D225
            $account === null => PayoutBlock::NoAccount,
            $open !== null => PayoutBlock::OpenRequest, // D221
            $availableCents <= 0 => PayoutBlock::NothingAvailable, // D220: a negative balance blocks too
            $availableCents < ($this->settings->minCents() ?? 1) => PayoutBlock::BelowMinimum,
            default => null,
        };
    }

    public function entries(Provider $provider, ?string $type, int $perPage): LengthAwarePaginator
    {
        $this->settings->ensureConfigured();

        return ProviderLedgerEntry::query()
            ->where('provider_id', $provider->id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->with(['booking:id,reference,title', 'payoutRequest:id,reference'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }
}
