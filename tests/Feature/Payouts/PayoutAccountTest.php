<?php

namespace Tests\Feature\Payouts;

use App\Enums\ProviderStatus;
use App\Models\ActivityEvent;
use App\Models\PayoutAccount;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPayouts;
use Tests\TestCase;

/**
 * Slice 11 §5/§7 (D216, D223): one payout account per provider, a validated Saudi IBAN, encrypted at rest, shown masked.
 */
class PayoutAccountTest extends TestCase
{
    use BuildsPayouts, RefreshDatabase;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    public function test_she_has_no_account_at_first(): void
    {
        $this->asProvider($this->noura)->getJson('/api/v1/provider/payout-account')->assertOk()->assertJsonPath('data', null);
    }

    public function test_she_saves_her_account_and_sees_it_masked(): void
    {
        $this->saveAccount($this->noura, ['iban' => 'sa03 8000 0000 6080 1016 7519'])->assertOk()
            ->assertJsonPath('data.holder_name', 'Noura Alharbi')
            ->assertJsonPath('data.iban_masked', 'SA•• •••• •••• •••• •••• 7519')
            ->assertJsonPath('data.iban_last4', '7519')
            ->assertJsonPath('data.bank_name', 'Al Rajhi Bank')
            ->assertJsonMissingPath('data.iban');

        $account = PayoutAccount::query()->sole();
        $this->assertSame(self::IBAN, $account->iban); // normalised
        $raw = DB::table('payout_accounts')->value('iban');
        $this->assertNotSame(self::IBAN, $raw); // encrypted at rest
        $this->assertStringNotContainsString(self::IBAN, (string) json_encode(ActivityEvent::query()->sole()->meta)); // never in history

        $this->asProvider($this->noura)->getJson('/api/v1/provider/payout-account')->assertOk()
            ->assertJsonPath('data.iban_masked', 'SA•• •••• •••• •••• •••• 7519');
    }

    public function test_the_arabic_bank_name_follows_the_language(): void
    {
        $this->saveAccount($this->noura);
        $this->asProvider($this->noura)->withHeader('Accept-Language', 'ar')->getJson('/api/v1/provider/payout-account')
            ->assertJsonPath('data.bank_name', 'مصرف الراجحي');
    }

    public function test_saving_again_replaces_the_one_account(): void
    {
        $this->saveAccount($this->noura)->assertOk();
        $this->saveAccount($this->noura, ['holder_name' => 'Noura A.', 'iban' => 'SA4420000001234567891234'])->assertOk()
            ->assertJsonPath('data.holder_name', 'Noura A.')
            ->assertJsonPath('data.bank_name', 'Riyad Bank');

        $this->assertSame(1, PayoutAccount::query()->count());
        $this->assertSame(['payout_account_added', 'payout_account_updated'], ActivityEvent::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_an_invalid_iban_is_refused(): void
    {
        foreach (['SA0380000000608010167518', 'AE070331234567890123456', 'SA03800000006080101675', 'not an iban'] as $iban) {
            $this->saveAccount($this->noura, ['iban' => $iban])->assertStatus(422)->assertJsonValidationErrors('iban');
        }
        $this->saveAccount($this->noura, ['holder_name' => ''])->assertStatus(422)->assertJsonValidationErrors('holder_name');
        $this->assertSame(0, PayoutAccount::query()->count());
    }

    public function test_an_inactive_provider_cannot_save(): void
    {
        $this->noura->update(['status' => ProviderStatus::Suspended]);

        $this->saveAccount($this->noura)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_clients_and_staff_have_no_payout_account(): void
    {
        $this->asClient()->getJson('/api/v1/provider/payout-account')->assertStatus(403);
        $this->asStaff($this->payoutsOfficer())->putJson('/api/v1/provider/payout-account', ['holder_name' => 'X', 'iban' => self::IBAN])->assertStatus(403);
    }
}
