<?php

namespace Tests\Feature\Database;

use App\Enums\AdminRole;
use App\Enums\ErrorCode;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutRequestStatus;
use App\Enums\ProviderAccountType;
use App\Enums\ProviderStatus;
use App\Exceptions\ApiException;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use App\Models\User;
use App\Services\Payouts\PayoutProcessing;
use App\Services\Payouts\PayoutRequests;
use App\Services\Settings\AppSettings;
use App\Support\SaudiIban;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 11 with two real MySQL sessions (contract §5/§6): payout writes hold her provider row, so a concurrent second
 * request waits and then sees the open request, and staff processing waits for a request being written.
 */
class PayoutLockingTest extends TestCase
{
    use UsesCommittedRows;

    private const IBAN = 'SA0380000000608010167519';

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $settings = app(AppSettings::class);
        $settings->set('payouts.commission_percent', '15');
        $settings->set('payouts.hold_days', 0);

        $user = User::factory()->provider()->create();
        $this->provider = Provider::query()->create([
            'user_id' => $user->id, 'account_type' => ProviderAccountType::Individual, 'display_name' => 'Noura Lens',
            'status' => ProviderStatus::Active,
        ]);
        PayoutAccount::query()->create([
            'provider_id' => $this->provider->id, 'holder_name' => 'Noura', 'iban' => self::IBAN, 'iban_last4' => '7519',
            'bank_code' => '80', 'bank_name_key' => SaudiIban::bank('80')['key'],
        ]);
        ProviderLedgerEntry::query()->create([
            'provider_id' => $this->provider->id, 'type' => LedgerEntryType::Earning, 'amount_cents' => 85000,
            'gross_cents' => 100000, 'commission_cents' => 15000, 'commission_bps' => 1500, 'available_at' => now()->subDay(),
            'entry_key' => 'earning:test',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    public function test_a_concurrent_second_request_waits_then_sees_the_open_one(): void
    {
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from providers where id = ? for update', [$this->provider->id]);
        $other->table('payout_requests')->insert([
            'reference' => 'PO-0000001', 'provider_id' => $this->provider->id, 'amount_cents' => 80000, 'status' => 'requested',
            'holder_name' => 'Noura', 'iban' => encrypt(self::IBAN, false), 'iban_last4' => '7519',
            'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('a', 64),
            'open_provider_id' => $this->provider->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = $this->provider->user;
        $this->shortLockWait();
        $this->assertWaits(fn () => app(PayoutRequests::class)->create($user, '80.00', (string) Str::uuid()));
        $other->commit();

        try {
            app(PayoutRequests::class)->create($user, '80.00', (string) Str::uuid());
            $this->fail('A second open payout request was accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::PayoutRequestOpen, $e->errorCode);
        }
        $this->assertSame(1, PayoutRequest::query()->count());
    }

    public function test_processing_waits_for_her_provider_row(): void
    {
        [$payout] = app(PayoutRequests::class)->create($this->provider->user, '100.00', (string) Str::uuid());
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = User::factory()->admin()->create();
        $staff->assignRole(AdminRole::SuperAdmin->value);

        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from providers where id = ? for update', [$this->provider->id]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(PayoutProcessing::class)->reject($staff, $payout, 'Wrong account', null));
        $other->rollBack();

        app(PayoutProcessing::class)->reject($staff, $payout, 'Wrong account', null);
        $this->assertSame(PayoutRequestStatus::Rejected, $payout->refresh()->status);
        $this->assertSame(1, ProviderLedgerEntry::query()->where('type', LedgerEntryType::PayoutReversal)->count());
    }
}
