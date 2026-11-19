<?php

namespace Tests\Feature\Wallet;

use App\Enums\BookingPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Models\AppSetting;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\User;
use App\Services\Payments\PaymentRefunds;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Owner decision 2026-11-19: the client tops up her wallet (directly until a gateway is connected, never in
 * production), pays a booking from it or by card, and every refund goes back to the wallet. Transaction history.
 */
class WalletMoneyTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->setting('wallet.direct_top_up', true);
        $this->setting('payments.refunds_to_wallet', true);
    }

    /** `null` removes the row (the service default applies). */
    private function setting(string $key, ?bool $value): void
    {
        $value === null ? AppSetting::query()->where('key', $key)->delete()
            : AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        app(AppSettings::class)->flush();
    }

    private function topUp(string|int|float $amount, ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->asClient($as)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/client/wallet/top-ups', ['amount' => $amount]);
    }

    private function balance(): string
    {
        return $this->asClient()->getJson('/api/v1/client/wallet')->json('data.balance.amount');
    }

    public function test_a_top_up_adds_any_amount_once_and_creates_the_wallet(): void
    {
        $key = (string) Str::uuid();
        $this->topUp('250.50', $key)->assertOk()->assertJsonPath('data.balance.amount', '250.50')->assertJsonPath('data.balance.is_spendable', true);
        $this->topUp('250.50', $key)->assertOk(); // retried tap: not added twice
        $this->topUp(3)->assertOk();
        $this->assertSame('253.50', $this->balance());

        foreach ([0, -5, '1.234', 'abc', 1000000001] as $bad) {
            $this->topUp($bad)->assertUnprocessable();
        }
        $this->flushHeaders()->asClient()->postJson('/api/v1/client/wallet/top-ups', ['amount' => 5])->assertUnprocessable(); // no Idempotency-Key
    }

    public function test_direct_top_up_is_off_when_the_setting_is_off_or_in_production(): void
    {
        $this->setting('wallet.direct_top_up', null);
        $this->topUp(10)->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');

        $this->setting('wallet.direct_top_up', true);
        $this->app['env'] = 'production';
        $this->topUp(10)->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
        $this->app['env'] = 'testing';
    }

    public function test_paying_a_booking_from_the_wallet(): void
    {
        $this->requirePayment();
        $offer = $this->offerFrom($this->noura); // 1000.00

        $this->topUp(400)->assertOk();
        $this->pay($offer, ['method' => 'wallet'])->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame('400.00', $this->balance());

        $this->topUp(700)->assertOk();
        $this->pay($offer, ['method' => 'wallet', 'token' => 'x'])->assertUnprocessable(); // no token with the wallet
        $this->pay($offer, ['method' => 'wallet'])->assertCreated()->assertJsonPath('data.status', 'succeeded');

        $booking = Booking::query()->sole();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame('100.00', $this->balance());
        $payment = Payment::query()->sole();
        $this->assertSame('wallet', $payment->gateway);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        $history = $this->asClient()->getJson('/api/v1/client/wallet/transactions')->assertOk()->json('data');
        $this->assertCount(3, $history);
        $this->assertSame('payment', $history[0]['type']);
        $this->assertSame('out', $history[0]['direction']);
        $this->assertSame('1000.00', $history[0]['amount']['amount']);
        $this->assertSame($booking->reference, $history[0]['booking']['reference']);
        $this->assertSame('top_up', $history[2]['type']);
    }

    public function test_every_refund_goes_back_to_the_wallet(): void
    {
        $this->requirePayment();
        $staff = User::factory()->admin()->create();

        // Paid by card: the refund still lands in her wallet.
        $card = $this->paid($this->offerFrom($this->noura));
        app(PaymentRefunds::class)->refund($card, '300', RefundKind::Staff, $staff, 'Goodwill', (string) Str::uuid());
        $this->assertSame('300.00', $this->balance());

        // Paid from the wallet: refunded back.
        $this->topUp(1000);
        $this->pay($this->offerFrom($this->noura, $this->eventRequest('12:00', 60, '2026-12-06')), ['method' => 'wallet'])->assertCreated();
        $this->assertSame('300.00', $this->balance());
        $walletPayment = Payment::query()->where('gateway', 'wallet')->sole();
        app(PaymentRefunds::class)->refund($walletPayment, '1000', RefundKind::Staff, $staff, 'Cancelled', (string) Str::uuid());
        $this->assertSame('1300.00', $this->balance());

        $types = collect($this->asClient()->getJson('/api/v1/client/wallet/transactions')->json('data'))->pluck('type')->all();
        $this->assertSame(['refund', 'payment', 'top_up', 'refund'], $types);
    }

    public function test_the_wallet_is_hers_only_and_cannot_be_closed_with_money(): void
    {
        $this->topUp(50)->assertOk();
        $this->asClient()->deleteJson('/api/v1/client/wallet')->assertStatus(409);

        $other = User::factory()->client()->create();
        $this->asClient($other)->getJson('/api/v1/client/wallet/transactions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($this->noura->user)->postJson('/api/v1/client/wallet/top-ups', ['amount' => 5])->assertForbidden();
    }
}
