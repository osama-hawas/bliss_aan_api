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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Wallet CRUD for clients and providers (D106, docs/wallet-contract.md). Every call is scoped to the signed-in
 * account's own wallet: there are no wallet ids in the URLs, so one account can never reach another's wallet. No method
 * moves money, writes ledger entries or touches bookings.
 */
class WalletService
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Her active wallet, or null.
     */
    public function current(User $user): ?Wallet
    {
        $this->assertOwnerType($user);

        return Wallet::query()->where('active_owner_id', $user->id)->where('status', WalletStatus::Active)->first();
    }

    /**
     * Creates her wallet; one active wallet per account (row lock + unique key → 409 WALLET_EXISTS).
     */
    public function create(User $user, ?string $label): Wallet
    {
        $this->assertOwnerType($user);

        try {
            return DB::transaction(function () use ($user, $label) {
                User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);
                if (Wallet::query()->where('active_owner_id', $user->id)->exists()) {
                    throw new ApiException(ErrorCode::WalletExists);
                }

                $wallet = Wallet::query()->create([
                    'user_id' => $user->id,
                    'owner_type' => $user->type,
                    'active_owner_id' => $user->id,
                    'label' => $this->clean($label),
                    'currency' => config('app.currency'),
                    'status' => WalletStatus::Active,
                ]);
                $this->activity->record($wallet, 'wallet_created', null, WalletStatus::Active, $user, ActivityVisibility::Internal);

                return $wallet;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::WalletExists);
        }
    }

    /**
     * The only editable information is the label (null clears it).
     */
    public function update(User $user, ?string $label): Wallet
    {
        $wallet = $this->current($user) ?? throw new ApiException(ErrorCode::NotFound);
        $wallet->update(['label' => $this->clean($label)]);

        return $wallet;
    }

    /**
     * "Delete" closes the wallet (D115): it becomes inactive, frees the one-active-wallet slot and is kept with every
     * ledger entry and history record — with or without ledger history. Nothing financial is ever hard-deleted.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $wallet = Wallet::query()->where('active_owner_id', $user->id)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::NotFound);

            $wallet->update(['status' => WalletStatus::Closed, 'closed_at' => now(), 'active_owner_id' => null]);
            $this->activity->record($wallet, 'wallet_closed', WalletStatus::Active, WalletStatus::Closed, $user, ActivityVisibility::Internal);
        });
    }

    /**
     * Informational balance = Σ `available` ledger entries, as a decimal string (never stored, never spendable).
     */
    public function balance(Wallet $wallet): string
    {
        $sum = $wallet->entries()->where('status', WalletLedgerEntry::STATUS_AVAILABLE)->sum('amount');

        return Money::normalize((string) ($sum ?: '0')); // MySQL returns the decimal sum as a string, no floats
    }

    private function assertOwnerType(User $user): void
    {
        if (! in_array($user->type, [UserType::Client, UserType::Provider], true)) {
            throw new ApiException(ErrorCode::Forbidden);
        }
    }

    private function clean(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : $label;
    }
}
