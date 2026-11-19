<?php

namespace App\Services\Wallets;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\UserType;
use App\Enums\WalletStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Audit\ActivityRecorder;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The client wallet's money (owner decision 2026-11-19, supersedes D106/D114–D116 for clients): top-ups, booking
 * payments and refunds are entries in `wallet_ledger_entries`; the balance is their sum. Every write locks the wallet
 * row, so two spends never take the same money. Amounts are decimal strings, compared in integer cents.
 */
class WalletLedger
{
    public const TOP_UP = 'top_up';

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    public function __construct(private readonly WalletService $wallets, private readonly ActivityRecorder $activity) {}

    /**
     * Her active wallet, created on first use (a top-up or a refund reaches her even before she opened one).
     */
    public function walletFor(User $user): Wallet
    {
        if ($user->type !== UserType::Client) {
            throw new ApiException(ErrorCode::Forbidden);
        }
        $wallet = $this->wallets->current($user);
        if ($wallet) {
            return $wallet;
        }
        try {
            return $this->wallets->create($user, null);
        } catch (ApiException $e) {
            if ($e->errorCode !== ErrorCode::WalletExists) {
                throw $e;
            }

            return $this->wallets->current($user) ?? throw $e;
        }
    }

    /**
     * Adds money. Replays the same `idempotency_key` instead of adding twice. Call inside a transaction or alone.
     */
    public function credit(User $user, string $amount, string $type, ?Model $source = null, array $meta = [], ?string $idempotencyKey = null): WalletLedgerEntry
    {
        $wallet = $this->walletFor($user);

        return DB::transaction(function () use ($wallet, $user, $amount, $type, $source, $meta, $idempotencyKey) {
            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if ($idempotencyKey !== null && ($existing = $this->replay($locked, $idempotencyKey))) {
                return $existing;
            }

            return $this->insert($locked, $user, Money::normalize($amount), $type, $source, $meta, $idempotencyKey);
        });
    }

    /**
     * Takes money: `409 INSUFFICIENT_BALANCE` when the balance does not cover it.
     */
    public function debit(User $user, string $amount, string $type, ?Model $source = null, array $meta = []): WalletLedgerEntry
    {
        $wallet = $this->walletFor($user);

        return DB::transaction(function () use ($wallet, $user, $amount, $type, $source, $meta) {
            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== WalletStatus::Active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if (Money::toCents($this->wallets->balance($locked)) < Money::toCents($amount)) {
                throw new ApiException(ErrorCode::InsufficientBalance, __('wallet.insufficient'));
            }

            return $this->insert($locked, $user, Money::fromCents(-Money::toCents($amount)), $type, $source, $meta, null);
        });
    }

    public function balanceOf(User $user): string
    {
        $wallet = $this->wallets->current($user);

        return $wallet ? $this->wallets->balance($wallet) : '0.00';
    }

    private function insert(Wallet $wallet, User $user, string $signed, string $type, ?Model $source, array $meta, ?string $key): WalletLedgerEntry
    {
        try {
            $entry = WalletLedgerEntry::query()->create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => $signed,
                'status' => WalletLedgerEntry::STATUS_AVAILABLE,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'meta' => $meta ?: null,
                'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($wallet, (string) $key) ?? throw new ApiException(ErrorCode::ServerError);
        }
        $this->activity->record($wallet, 'wallet_'.$type, null, null, $user, ActivityVisibility::Internal, ['entry_id' => $entry->id, 'amount' => $signed]);

        return $entry;
    }

    private function replay(Wallet $wallet, string $key): ?WalletLedgerEntry
    {
        return WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->where('idempotency_key', $key)->first();
    }
}
