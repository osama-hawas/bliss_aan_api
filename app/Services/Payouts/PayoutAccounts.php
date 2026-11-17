<?php

namespace App\Services\Payouts;

use App\Enums\ActivityVisibility;
use App\Models\PayoutAccount;
use App\Models\Provider;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Providers\ProviderSetup;
use App\Support\SaudiIban;
use Illuminate\Support\Facades\DB;

/**
 * Her one payout account (D216): holder name + validated Saudi IBAN, encrypted. Open requests keep their snapshot.
 */
class PayoutAccounts
{
    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly ActivityRecorder $activity,
    ) {}

    public function of(Provider $provider): ?PayoutAccount
    {
        return PayoutAccount::query()->where('provider_id', $provider->id)->first();
    }

    public function save(User $user, string $holderName, string $iban): PayoutAccount
    {
        $provider = $this->setup->activeProvider($user); // writes need an active provider (contract §5)
        $iban = SaudiIban::normalize($iban);
        $bankCode = SaudiIban::bankCode($iban);

        return DB::transaction(function () use ($provider, $user, $holderName, $iban, $bankCode) {
            Provider::query()->whereKey($provider->id)->lockForUpdate()->first(['id']);
            $account = PayoutAccount::query()->where('provider_id', $provider->id)->lockForUpdate()->first();
            $event = $account ? 'payout_account_updated' : 'payout_account_added';
            $account ??= new PayoutAccount(['provider_id' => $provider->id]);
            $account->fill([
                'holder_name' => trim($holderName),
                'iban' => $iban,
                'iban_last4' => SaudiIban::last4($iban),
                'bank_code' => $bankCode,
                'bank_name_key' => SaudiIban::bank($bankCode)['key'] ?? null,
                'verified_at' => null,
            ])->save();
            // Never the IBAN itself in history (contract §7).
            $this->activity->record($account, $event, null, null, $user, ActivityVisibility::Internal,
                ['iban_last4' => $account->iban_last4, 'bank_code' => $bankCode]);

            return $account;
        });
    }
}
