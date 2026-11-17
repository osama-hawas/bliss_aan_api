<?php

namespace Tests\Feature\Finance;

use App\Enums\NotificationEvent;
use App\Models\ActivityEvent;
use App\Models\AuditLog;
use App\Models\LedgerAdjustment;
use App\Models\Provider;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsFinance;
use Tests\TestCase;

/**
 * Slice 19 §4 (D286–D293): staff ledger adjustments — limits as settings, four-eyes approval, links, one ledger row,
 * audit + history, provider notification. Written by S19, run after the S10/S11 merge (provider_ledger_entries,
 * payout_requests).
 */
class LedgerAdjustmentTest extends TestCase
{
    use BuildsBookings, BuildsFinance, RefreshDatabase;

    private Provider $noura;

    private User $alice;

    private User $bea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->alice = $this->financeStaff(['name' => 'Alice']);
        $this->bea = $this->financeStaff(['name' => 'Bea']);
    }

    private function body(array $over = []): array
    {
        return ['provider_id' => $this->noura->id, 'direction' => 'credit', 'amount' => '150.00', 'reason_code' => 'goodwill', 'reason' => 'Late payout apology', ...$over];
    }

    public function test_not_configured_until_a_maximum_is_set(): void
    {
        $this->adjust($this->alice, $this->body())->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
        $this->assertSame(0, LedgerAdjustment::query()->count());
    }

    public function test_amount_over_the_maximum_is_refused(): void
    {
        $this->setLimits('100.00', null);

        $this->adjust($this->alice, $this->body(['amount' => '100.01']))->assertStatus(422)
            ->assertJsonPath('code', 'ADJUSTMENT_LIMIT_EXCEEDED')->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_below_the_approval_amount_posts_at_once_with_one_ledger_row(): void
    {
        $this->setLimits('1000.00', '500.00');

        $res = $this->adjust($this->alice, $this->body())->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.requires_approval', false)
            ->assertJsonPath('data.amount.amount', '150.00')
            ->assertJsonPath('data.signed_amount.amount', '150.00');

        $entries = $this->adjustmentEntries($this->noura);
        $this->assertCount(1, $entries);
        $this->assertSame(15000, (int) $entries[0]->amount_cents);
        $this->assertSame('adjustment:'.$res->json('data.id'), $entries[0]->entry_key);
        $this->assertTrue(AuditLog::query()->where('action', 'ledger_adjustment_posted')->exists());
    }

    public function test_at_or_above_the_approval_amount_waits_for_a_second_approver(): void
    {
        $this->setLimits('1000.00', '150.00');

        $this->adjust($this->alice, $this->body())->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.requires_approval', true)
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.can_reject', true)
            ->assertJsonPath('data.is_own', true);
        $this->assertCount(0, $this->adjustmentEntries($this->noura));
    }

    public function test_null_approval_amount_means_every_adjustment_needs_approval(): void
    {
        $this->setLimits('1000.00', null);

        $this->adjust($this->alice, $this->body(['amount' => '0.01']))->assertCreated()->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_requester_cannot_approve_her_own_request(): void
    {
        $this->setLimits('1000.00', null);
        $id = $this->adjust($this->alice, $this->body())->json('data.id');

        $this->actingAsApi($this->alice)->postJson(self::FINANCE_URL."/adjustments/{$id}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertCount(0, $this->adjustmentEntries($this->noura));
    }

    public function test_another_approver_posts_once_and_notifies_the_provider(): void
    {
        $this->setLimits('1000.00', null);
        $id = $this->adjust($this->alice, $this->body(['direction' => 'debit', 'reason_code' => 'penalty']))->json('data.id');

        $notifier = Mockery::spy(Notifier::class);
        $this->app->instance(Notifier::class, $notifier);

        $this->actingAsApi($this->bea)->postJson(self::FINANCE_URL."/adjustments/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.decided_by.name', 'Bea')
            ->assertJsonPath('data.signed_amount.amount', '-150.00');
        // Approving again changes nothing.
        $this->actingAsApi($this->bea)->postJson(self::FINANCE_URL."/adjustments/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'posted');

        $entries = $this->adjustmentEntries($this->noura);
        $this->assertCount(1, $entries);
        $this->assertSame(-15000, (int) $entries[0]->amount_cents);
        $notifier->shouldHaveReceived('send')->once()->withArgs(fn ($recipient, $event) => $recipient === $this->noura->user_id && $event === NotificationEvent::LedgerDebitPosted);
        $this->assertTrue(AuditLog::query()->where('action', 'ledger_adjustment_approved')->where('actor_id', $this->bea->id)->exists());
        $this->assertSame(['ledger_adjustment_requested', 'ledger_adjustment_posted'],
            ActivityEvent::query()->where('subject_id', $id)->where('subject_type', (new LedgerAdjustment)->getMorphClass())->orderBy('id')->pluck('event')->all());
    }

    public function test_reject_by_another_and_cancel_by_the_requester(): void
    {
        $this->setLimits('1000.00', null);
        $first = $this->adjust($this->alice, $this->body())->json('data.id');
        $second = $this->adjust($this->alice, $this->body(['amount' => '20.00']))->json('data.id');

        $this->actingAsApi($this->bea)->postJson(self::FINANCE_URL."/adjustments/{$first}/reject", ['reason' => 'Not justified'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'Not justified');
        $this->actingAsApi($this->alice)->postJson(self::FINANCE_URL."/adjustments/{$second}/reject", ['reason' => 'Typo in amount'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAsApi($this->bea)->postJson(self::FINANCE_URL."/adjustments/{$first}/approve")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($this->bea)->postJson(self::FINANCE_URL."/adjustments/{$first}/reject", ['reason' => 'x'])->assertStatus(422);
        $this->assertCount(0, $this->adjustmentEntries($this->noura));
    }

    public function test_posted_adjustment_cannot_be_rejected(): void
    {
        $this->setLimits('1000.00', '500.00');
        $id = $this->adjust($this->alice, $this->body())->json('data.id');

        $this->actingAsApi($this->bea)->postJson(self::FINANCE_URL."/adjustments/{$id}/reject", ['reason' => 'Too late'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_same_key_replays_and_other_body_is_a_duplicate(): void
    {
        $this->setLimits('1000.00', '500.00');
        $key = (string) Str::uuid();

        $id = $this->adjust($this->alice, $this->body(), $key)->assertCreated()->json('data.id');
        $this->adjust($this->alice, $this->body(), $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->adjust($this->alice, $this->body(['amount' => '99.00']), $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertCount(1, $this->adjustmentEntries($this->noura));
    }

    public function test_validation_and_category_direction_rules(): void
    {
        $this->setLimits('1000.00', '500.00');

        $this->adjust($this->alice, $this->body(['amount' => '0']))->assertStatus(422)->assertJsonStructure(['errors' => ['amount']]);
        $this->adjust($this->alice, $this->body(['amount' => '10.001']))->assertStatus(422);
        $this->adjust($this->alice, $this->body(['reason' => 'x']))->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->adjust($this->alice, $this->body(['direction' => 'debit', 'reason_code' => 'goodwill']))->assertStatus(422)->assertJsonStructure(['errors' => ['direction']]);
        $this->adjust($this->alice, $this->body(['reason_code' => 'penalty']))->assertStatus(422)->assertJsonStructure(['errors' => ['direction']]);
        $this->adjust($this->alice, $this->body(['provider_id' => 999999]))->assertStatus(422)->assertJsonStructure(['errors' => ['provider_id']]);
        $this->actingAsApi($this->alice)->postJson(self::FINANCE_URL.'/adjustments', $this->body())->assertStatus(422)->assertJsonStructure(['errors' => ['idempotency_key']]);
    }

    public function test_booking_correction_needs_a_booking_of_hers(): void
    {
        $this->setLimits('1000.00', '500.00');
        $huda = $this->matchingProvider('Huda Studio');
        $hers = $this->booking($this->noura);
        $other = $this->booking($huda);

        $this->adjust($this->alice, $this->body(['reason_code' => 'booking_correction']))->assertStatus(422)->assertJsonStructure(['errors' => ['booking_id']]);
        $this->adjust($this->alice, $this->body(['reason_code' => 'booking_correction', 'booking_id' => $other->id]))->assertStatus(422);
        $this->adjust($this->alice, $this->body(['reason_code' => 'booking_correction', 'booking_id' => $hers->id]))->assertCreated()
            ->assertJsonPath('data.booking.reference', $hers->reference);
    }

    public function test_payout_returned_rules(): void
    {
        $this->setLimits('5000.00', '9000.00');
        $paid = $this->payoutRequest($this->noura, 'paid', 30000);
        $open = $this->payoutRequest($this->noura, 'requested', 10000);
        $body = fn (array $o = []) => $this->body(['reason_code' => 'payout_returned', 'amount' => '300.00', 'payout_request_id' => $paid, ...$o]);

        $this->adjust($this->alice, $body(['payout_request_id' => null]))->assertStatus(422)->assertJsonStructure(['errors' => ['payout_request_id']]);
        $this->adjust($this->alice, $body(['payout_request_id' => $open]))->assertStatus(422)->assertJsonStructure(['errors' => ['payout_request_id']]);
        $this->adjust($this->alice, $body(['amount' => '300.01']))->assertStatus(422)->assertJsonStructure(['errors' => ['amount']]);
        $this->adjust($this->alice, $body(['direction' => 'debit']))->assertStatus(422);

        $this->adjust($this->alice, $body())->assertCreated()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.payout.id', $paid);
        $this->adjust($this->alice, $body())->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
    }

    public function test_permissions(): void
    {
        $this->setLimits('1000.00', null);
        $viewer = $this->staffWith(['finance.view']);
        $nobody = $this->staffWith(['bookings.view']);
        $id = $this->adjust($this->alice, $this->body())->json('data.id');

        $this->adjust($viewer, $this->body())->assertForbidden();
        $this->actingAsApi($viewer)->postJson(self::FINANCE_URL."/adjustments/{$id}/approve")->assertForbidden();
        $this->actingAsApi($viewer)->getJson(self::FINANCE_URL."/adjustments/{$id}")->assertOk()->assertJsonPath('data.can_approve', false)
            ->assertJsonCount(1, 'data.history');
        $this->actingAsApi($nobody)->getJson(self::FINANCE_URL.'/adjustments')->assertForbidden();
        $this->actingAsApi($this->noura->user)->getJson(self::FINANCE_URL.'/adjustments')->assertForbidden();
    }

    public function test_list_filters_and_limits_endpoint(): void
    {
        $this->setLimits('1000.00', '500.00');
        $this->adjust($this->alice, $this->body())->assertCreated();
        $this->adjust($this->alice, $this->body(['amount' => '600.00']))->assertCreated();

        $this->actingAsApi($this->bea)->getJson(self::FINANCE_URL.'/adjustments?filter[status]=pending_approval')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($this->bea)->getJson(self::FINANCE_URL.'/adjustments?sort=-amount')->assertOk()->assertJsonPath('data.0.amount.amount', '600.00');
        $this->actingAsApi($this->bea)->getJson(self::FINANCE_URL.'/adjustments?q=Noura')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($this->bea)->getJson(self::FINANCE_URL.'/adjustment-limits')->assertOk()
            ->assertJsonPath('data.max_amount.amount', '1000.00')->assertJsonPath('data.approval_amount.amount', '500.00')
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.approval_always', false);
    }
}
