<?php

namespace Tests\Feature\Wallet;

use App\Enums\AdminRole;
use App\Enums\WalletStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Offers\OfferSender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsAvailability;
use Tests\TestCase;

/**
 * Wallet CRUD for clients and providers (D106, docs/wallet-contract.md): own wallet only, one active wallet per
 * account, label is the only editable field, delete closes and keeps history, balance derived from ledger entries and
 * never spendable, bookings untouched.
 */
class WalletTest extends TestCase
{
    use BuildsApplications, BuildsAvailability, RefreshDatabase;

    private const CLIENT = '/api/v1/client/wallet';

    private const PROVIDER = '/api/v1/provider/wallet';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    private function as(User $user): static
    {
        return $this->actingAsApi($user)->withHeader('Accept-Language', 'en');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function audiences(): array
    {
        return [
            'client' => [self::CLIENT, 'client'],
            'provider' => [self::PROVIDER, 'provider'],
        ];
    }

    private function owner(string $who): User
    {
        return $who === 'client' ? $this->client : $this->noura->user;
    }

    /**
     * @dataProvider audiences
     */
    public function test_full_crud_for_each_account_type(string $url, string $who): void
    {
        $user = $this->owner($who);

        $this->as($user)->getJson($url)->assertOk()->assertJsonPath('data', null); // empty state

        $id = $this->as($user)->postJson($url, ['label' => '  My wallet  '])->assertCreated()
            ->assertJsonPath('message', 'Wallet created.')
            ->assertJsonPath('data.owner_type', $who)
            ->assertJsonPath('data.label', 'My wallet')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.balance.amount', '0.00')
            ->assertJsonPath('data.balance.currency', 'SAR')
            // Owner decision 2026-11-19: a client's balance pays bookings; a provider's stays informational.
            ->assertJsonPath('data.balance.is_spendable', $who === 'client')
            ->json('data.id');
        $this->assertSame($user->id, Wallet::query()->findOrFail($id)->user_id);

        $this->as($user)->getJson($url)->assertOk()->assertJsonPath('data.id', $id);
        $this->as($user)->patchJson($url, ['label' => 'Savings'])->assertOk()->assertJsonPath('data.label', 'Savings');
        $this->as($user)->patchJson($url, ['label' => null])->assertOk()->assertJsonPath('data.label', null);

        // Duplicate prevention.
        $this->as($user)->postJson($url, [])->assertStatus(409)->assertJsonPath('code', 'WALLET_EXISTS');
        $this->assertSame(1, Wallet::query()->where('user_id', $user->id)->count());

        // Delete closes and keeps history; a new wallet may then be created.
        $this->as($user)->deleteJson($url)->assertOk()->assertJsonPath('message', 'Wallet deleted.');
        $closed = Wallet::query()->findOrFail($id);
        $this->assertSame(WalletStatus::Closed, $closed->status);
        $this->assertNull($closed->active_owner_id);
        $this->assertNotNull($closed->closed_at);
        $this->as($user)->getJson($url)->assertOk()->assertJsonPath('data', null);
        $this->as($user)->patchJson($url, ['label' => 'x'])->assertNotFound();
        $this->as($user)->deleteJson($url)->assertNotFound();
        $this->as($user)->postJson($url)->assertCreated();
        $this->assertSame(2, Wallet::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('activity_events', ['subject_id' => $id, 'event' => 'wallet_closed']);
    }

    public function test_arabic_labels(): void
    {
        $this->actingAsApi($this->client)->withHeader('Accept-Language', 'ar')->postJson(self::CLIENT)->assertCreated()
            ->assertJsonPath('message', 'تم إنشاء المحفظة.')
            ->assertJsonPath('data.status_label', 'نشطة')
            ->assertJsonPath('data.balance.note', __('wallet.balance_note_client', [], 'ar'));
    }

    public function test_each_account_reaches_only_its_own_wallet(): void
    {
        $clientWallet = $this->as($this->client)->postJson(self::CLIENT, ['label' => 'Client'])->json('data.id');
        $providerWallet = $this->as($this->noura->user)->postJson(self::PROVIDER, ['label' => 'Provider'])->json('data.id');

        // Wrong audience routes are forbidden by account type.
        $this->as($this->client)->getJson(self::PROVIDER)->assertForbidden();
        $this->as($this->client)->patchJson(self::PROVIDER, ['label' => 'x'])->assertForbidden();
        $this->as($this->noura->user)->getJson(self::CLIENT)->assertForbidden();
        $this->as($this->noura->user)->deleteJson(self::CLIENT)->assertForbidden();
        $admin = $this->staff(AdminRole::SuperAdmin);
        $this->as($admin)->getJson(self::CLIENT)->assertForbidden();
        $this->as($admin)->getJson(self::PROVIDER)->assertForbidden();

        // Another client sees only her own (empty) wallet and cannot change or close Sara's.
        $other = User::factory()->client()->create();
        $this->as($other)->getJson(self::CLIENT)->assertOk()->assertJsonPath('data', null);
        $this->as($other)->deleteJson(self::CLIENT)->assertNotFound();
        $this->as($other)->patchJson(self::CLIENT, ['label' => 'hijack'])->assertNotFound();
        $this->assertSame('Client', Wallet::query()->find($clientWallet)->label);
        $this->assertSame(WalletStatus::Active, Wallet::query()->find($clientWallet)->status);

        $this->as($this->client)->getJson(self::CLIENT)->assertJsonPath('data.id', $clientWallet);
        $this->as($this->noura->user)->getJson(self::PROVIDER)->assertJsonPath('data.id', $providerWallet);
    }

    public function test_guests_are_unauthenticated(): void
    {
        foreach ([self::CLIENT, self::PROVIDER] as $url) {
            $this->getJson($url)->assertUnauthorized();
            $this->postJson($url)->assertUnauthorized();
        }
    }

    public function test_validation_and_no_balance_manipulation(): void
    {
        $this->as($this->client)->postJson(self::CLIENT, ['label' => str_repeat('a', 61)])->assertUnprocessable()->assertJsonValidationErrors(['label']);
        $this->as($this->client)->postJson(self::CLIENT, ['balance' => '500', 'amount' => 5, 'currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors(['balance', 'amount', 'currency']);
        $this->as($this->client)->postJson(self::CLIENT)->assertCreated();
        $this->as($this->client)->patchJson(self::CLIENT, ['balance' => '1000'])->assertUnprocessable()->assertJsonValidationErrors(['balance']);
        $this->as($this->client)->getJson(self::CLIENT)->assertJsonPath('data.balance.amount', '0.00');

        // Only `top-ups` exists (owner decision 2026-11-19); nothing else moves money.
        foreach (['deposit', 'withdraw', 'transfer', 'pay', 'refund', 'top-up'] as $action) {
            $this->as($this->client)->postJson(self::CLIENT."/{$action}", ['amount' => 10])->assertStatus(405);
        }
    }

    public function test_balance_is_derived_from_available_ledger_entries_only(): void
    {
        $this->as($this->noura->user)->postJson(self::PROVIDER)->assertCreated();
        $wallet = Wallet::query()->sole();
        // The table is an extension point: written here directly, never by any endpoint in the D106 scope.
        $wallet->entries()->createMany([
            ['type' => 'earning', 'amount' => '150.25', 'status' => WalletLedgerEntry::STATUS_AVAILABLE],
            ['type' => 'adjustment', 'amount' => '-50.00', 'status' => WalletLedgerEntry::STATUS_AVAILABLE],
            ['type' => 'earning', 'amount' => '999.00', 'status' => 'pending'],
        ]);
        $this->as($this->noura->user)->getJson(self::PROVIDER)->assertJsonPath('data.balance.amount', '100.25')
            ->assertJsonPath('data.balance.is_spendable', false);

        // A wallet with a balance stays open (owner decision 2026-11-19); once it is back to zero it may be closed (D115)
        // and its entries stay intact.
        $this->as($this->noura->user)->deleteJson(self::PROVIDER)->assertStatus(409);
        $wallet->entries()->create(['type' => 'adjustment', 'amount' => '-100.25', 'status' => WalletLedgerEntry::STATUS_AVAILABLE]);
        $this->as($this->noura->user)->deleteJson(self::PROVIDER)->assertOk();
        $this->assertSame(WalletStatus::Closed, $wallet->fresh()->status);
        $this->assertNull($wallet->fresh()->active_owner_id);
        $this->assertSame(4, WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->count());
        $this->as($this->noura->user)->getJson(self::PROVIDER)->assertJsonPath('data', null); // never the active wallet again

        // A new wallet starts empty; the closed wallet's history is untouched.
        $this->as($this->noura->user)->postJson(self::PROVIDER)->assertCreated()->assertJsonPath('data.balance.amount', '0.00');
        $this->assertSame(4, WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->count());
        $this->assertSame(1, Wallet::query()->where('user_id', $this->noura->user_id)->where('status', WalletStatus::Active)->count());
    }

    public function test_one_active_wallet_per_account_is_a_database_constraint(): void
    {
        $this->as($this->client)->postJson(self::CLIENT)->assertCreated();
        $this->expectException(UniqueConstraintViolationException::class);
        Wallet::query()->create(['user_id' => $this->client->id, 'owner_type' => 'client', 'active_owner_id' => $this->client->id, 'currency' => 'SAR', 'status' => 'active']);
    }

    public function test_wallets_play_no_part_in_booking_acceptance(): void
    {
        // A client without any wallet books; a client with a wallet books and her wallet is untouched.
        $accept = function (User $client) {
            $request = $this->created($this->openBody(['event_date' => '2026-12-05', 'start_time' => (string) (10 + Wallet::query()->count()).':00', 'duration_minutes' => 60]));
            $offer = app(OfferSender::class)->send($this->noura, $request, (string) Str::uuid(), [
                'title' => 'Offer', 'items' => [['description' => 'Photo', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
            ])[0];

            return $this->as($client)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/client/offers/{$offer->id}/accept");
        };

        $accept($this->client)->assertCreated()->assertJsonPath('data.payment_status', 'not_required');
        $this->assertSame(0, Wallet::query()->count());

        $this->as($this->client)->postJson(self::CLIENT)->assertCreated();
        $this->as($this->noura->user)->postJson(self::PROVIDER)->assertCreated();
        $snapshot = fn () => Wallet::query()->orderBy('id')->get(['id', 'label', 'status', 'active_owner_id', 'updated_at'])->toJson();
        $before = $snapshot();
        $accept($this->client)->assertCreated();
        $this->assertSame(2, Booking::query()->count());
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
        $this->as($this->client)->getJson(self::CLIENT)->assertJsonPath('data.balance.amount', '0.00');
    }
}
