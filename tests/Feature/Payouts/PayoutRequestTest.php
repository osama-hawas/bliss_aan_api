<?php

namespace Tests\Feature\Payouts;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutRequestStatus;
use App\Enums\ProviderStatus;
use App\Models\Booking;
use App\Models\PayoutRequest;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayouts;
use Tests\TestCase;

/**
 * Slice 11 §5 (D216, D221, D222, D225): a payout request debits her available balance at once, one open request at a
 * time, idempotent by Idempotency-Key, from `payouts.min_amount` up to the available balance.
 */
class PayoutRequestTest extends TestCase
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

    /**
     * 850.00 available (1000 gross, 15 %, hold 7 days, now past the hold) and a saved account.
     */
    private function readyToRequest(?string $min = null): void
    {
        $this->configurePayouts('15', 7, $min);
        $this->paidCompletedBooking($this->noura);
        $this->at('2026-12-13T09:00:00Z');
        $this->saveAccount($this->noura)->assertOk();
    }

    public function test_she_requests_part_of_her_available_balance(): void
    {
        $this->readyToRequest();

        $res = $this->requestPayout($this->noura, '500.50')->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.amount.amount', '500.50')
            ->assertJsonPath('data.iban_masked', 'SA•• •••• •••• •••• •••• 7519')
            ->assertJsonPath('data.holder_name', 'Noura Alharbi')
            ->assertJsonMissingPath('data.internal_note');
        $this->assertMatchesRegularExpression('/^PO-\d{7}$/', $res->json('data.reference'));

        $payout = PayoutRequest::query()->sole();
        $this->assertSame(50050, $payout->amount_cents);
        $this->assertSame(-50050, ProviderLedgerEntry::query()->where('type', LedgerEntryType::Payout)->sole()->amount_cents);
        $this->earnings($this->noura)->assertOk()
            ->assertJsonPath('data.available.amount', '349.50')
            ->assertJsonPath('data.in_payout.amount', '500.50')
            ->assertJsonPath('data.can_request', false)
            ->assertJsonPath('data.request_block', 'open_request')
            ->assertJsonPath('data.open_payout_request.reference', $payout->reference);
    }

    public function test_the_same_key_replays_and_another_body_conflicts(): void
    {
        $this->readyToRequest();
        $key = (string) Str::uuid();

        $first = $this->requestPayout($this->noura, '100', $key)->assertCreated();
        $this->requestPayout($this->noura, '100.00', $key)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->requestPayout($this->noura, '200', $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');

        $this->assertSame(1, PayoutRequest::query()->count());
        $this->assertSame(1, ProviderLedgerEntry::query()->where('type', LedgerEntryType::Payout)->count());
    }

    public function test_one_open_request_at_a_time(): void
    {
        $this->readyToRequest();
        $this->requestPayout($this->noura, '100')->assertCreated();

        $this->requestPayout($this->noura, '100')->assertStatus(409)->assertJsonPath('code', 'PAYOUT_REQUEST_OPEN');
    }

    public function test_more_than_available_is_refused(): void
    {
        $this->readyToRequest();

        $this->requestPayout($this->noura, '850.01')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
        $this->requestPayout($this->noura, '850.00')->assertCreated();
    }

    public function test_held_money_cannot_be_requested(): void
    {
        $this->configurePayouts('15', 7);
        $this->paidCompletedBooking($this->noura);
        $this->saveAccount($this->noura);

        $this->earnings($this->noura)->assertJsonPath('data.request_block', 'nothing_available');
        $this->requestPayout($this->noura, '1')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
    }

    public function test_the_minimum_applies(): void
    {
        $this->readyToRequest('100.00');

        $this->earnings($this->noura)->assertJsonPath('data.min_amount.amount', '100.00');
        $this->requestPayout($this->noura, '99.99')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->requestPayout($this->noura, '100')->assertCreated();
    }

    public function test_below_minimum_block_when_the_balance_is_too_small(): void
    {
        $this->readyToRequest('900.00');

        $this->earnings($this->noura)->assertJsonPath('data.can_request', false)->assertJsonPath('data.request_block', 'below_minimum');
    }

    public function test_an_account_is_required(): void
    {
        $this->configurePayouts('15', 0);
        $this->paidCompletedBooking($this->noura);

        $this->earnings($this->noura)->assertJsonPath('data.request_block', 'no_account')->assertJsonPath('data.payout_account', null);
        $this->requestPayout($this->noura, '10')->assertStatus(409)->assertJsonPath('code', 'PAYOUT_ACCOUNT_REQUIRED');
    }

    public function test_not_configured_refuses_requests(): void
    {
        $this->saveAccount($this->noura);

        $this->requestPayout($this->noura, '10')->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
    }

    public function test_an_inactive_provider_reads_but_cannot_request(): void
    {
        $this->readyToRequest();
        $this->noura->update(['status' => ProviderStatus::Suspended]);

        $this->earnings($this->noura)->assertOk()->assertJsonPath('data.request_block', 'provider_inactive');
        $this->requestPayout($this->noura, '10')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_amount_and_key_are_validated(): void
    {
        $this->readyToRequest();

        foreach (['0', '0.00', '-5', '10.123', 'abc', ''] as $amount) {
            $this->requestPayout($this->noura, $amount)->assertStatus(422)->assertJsonValidationErrors('amount');
        }
        $this->asProvider($this->noura)->postJson('/api/v1/provider/payout-requests', ['amount' => '10'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(0, PayoutRequest::query()->count());
    }

    public function test_a_negative_balance_blocks_requests(): void
    {
        $this->readyToRequest();
        $this->requestPayout($this->noura, '850')->assertCreated();
        $payout = PayoutRequest::query()->sole();
        $payout->update(['status' => PayoutRequestStatus::Paid, 'open_provider_id' => null]);
        $booking = Booking::query()->where('provider_id', $this->noura->id)->sole();
        $this->settle($this->settledRefund($booking, '1000.00')); // refunded after the payout: −850

        $this->earnings($this->noura)->assertJsonPath('data.available.amount', '-850.00')
            ->assertJsonPath('data.request_block', 'nothing_available');
    }

    public function test_she_lists_and_opens_only_her_own_requests(): void
    {
        $this->readyToRequest();
        $id = $this->requestPayout($this->noura, '100')->json('data.id');
        $huda = $this->matchingProvider('Huda Studio');

        $this->asProvider($this->noura)->getJson('/api/v1/provider/payout-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->asProvider($this->noura)->getJson('/api/v1/provider/payout-requests?filter[status]=paid')->assertOk()->assertJsonCount(0, 'data');
        $this->asProvider($this->noura)->getJson("/api/v1/provider/payout-requests/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->asProvider($huda)->getJson("/api/v1/provider/payout-requests/{$id}")->assertNotFound();
        $this->asProvider($huda)->getJson('/api/v1/provider/payout-requests')->assertOk()->assertJsonCount(0, 'data');
    }
}
