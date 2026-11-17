<?php

namespace App\Services\Payments;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Integrations\Payments\CardDetails;
use App\Integrations\Payments\InvalidPaymentToken;
use App\Integrations\Payments\PaymentGateway;
use App\Models\SavedPaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saved cards as gateway tokens (D188, D193, slice-10-contract.md §7). Every write is serialized per account by the
 * user row lock; one default at most; delete is a soft delete (past payments keep their card snapshot).
 */
class SavedPaymentMethods
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @return Collection<int, SavedPaymentMethod>
     */
    public function list(User $user): Collection
    {
        return SavedPaymentMethod::query()->where('user_id', $user->id)
            ->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    public function find(User $user, int $id): SavedPaymentMethod
    {
        return SavedPaymentMethod::query()->where('user_id', $user->id)->findOrFail($id);
    }

    /**
     * @return array{0: SavedPaymentMethod, 1: bool} the method and whether it was created now
     */
    public function add(User $user, string $token): array
    {
        return $this->remember($user, $token, $this->describe($token));
    }

    /**
     * @throws ApiException 422 PAYMENT_METHOD_INVALID
     */
    public function describe(string $token): CardDetails
    {
        try {
            return $this->gateway->describeToken($token);
        } catch (InvalidPaymentToken) {
            throw new ApiException(ErrorCode::PaymentMethodInvalid, null, ['token' => [__('errors.PAYMENT_METHOD_INVALID')]]);
        }
    }

    /**
     * Saves an already described token (also used after a successful payment with `save_method`).
     *
     * @return array{0: SavedPaymentMethod, 1: bool}
     */
    public function remember(User $user, string $token, CardDetails $card): array
    {
        $hash = hash('sha256', $token);
        $gateway = $this->gateway->name();

        try {
            return DB::transaction(function () use ($user, $token, $card, $hash, $gateway) {
                User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);
                $existing = SavedPaymentMethod::query()->where('user_id', $user->id)->where('gateway', $gateway)
                    ->where('active_token_hash', $hash)->first();
                if ($existing) {
                    return [$existing, false];
                }
                $hasDefault = SavedPaymentMethod::query()->where('user_id', $user->id)->where('is_default', true)->exists();

                return [SavedPaymentMethod::query()->create([
                    'user_id' => $user->id,
                    'gateway' => $gateway,
                    'gateway_token' => $token,
                    'token_hash' => $hash,
                    'active_token_hash' => $hash,
                    'brand' => $card->brand,
                    'last4' => $card->last4,
                    'exp_month' => $card->expMonth,
                    'exp_year' => $card->expYear,
                    'is_default' => ! $hasDefault,
                ]), true];
            });
        } catch (UniqueConstraintViolationException) {
            return [SavedPaymentMethod::query()->where('user_id', $user->id)->where('gateway', $gateway)->where('active_token_hash', $hash)->firstOrFail(), false];
        }
    }

    public function makeDefault(User $user, int $id): SavedPaymentMethod
    {
        return DB::transaction(function () use ($user, $id) {
            User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);
            $method = $this->find($user, $id);
            SavedPaymentMethod::query()->where('user_id', $user->id)->whereKeyNot($method->id)->where('is_default', true)->update(['is_default' => false]);
            $method->update(['is_default' => true]);

            return $method;
        });
    }

    public function delete(User $user, int $id): void
    {
        $token = DB::transaction(function () use ($user, $id) {
            User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);
            $method = $this->find($user, $id);
            $wasDefault = $method->is_default;
            $token = $method->gateway_token;
            $method->update(['is_default' => false, 'active_token_hash' => null]);
            $method->delete();
            if ($wasDefault) {
                SavedPaymentMethod::query()->where('user_id', $user->id)->orderByDesc('id')->first()?->update(['is_default' => true]);
            }

            return $token;
        });

        try {
            $this->gateway->deleteToken($token);
        } catch (Throwable $e) {
            Log::warning('Gateway token deletion failed (best effort).', ['user_id' => $user->id, 'method_id' => $id]);
        }
    }
}
