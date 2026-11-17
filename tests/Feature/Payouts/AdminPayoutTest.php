<?php

namespace Tests\Feature\Payouts;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutRequestStatus;
use App\Models\AuditLog;
use App\Models\PayoutRequest;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsPayouts;
use Tests\TestCase;

/**
 * Slice 11 §6 (D216, D223, D224): staff work the payouts queue manually — mark paid with a transfer reference or reject
 * with a reason (the amount returns to her balance). Idempotent, audit-logged, the provider is notified (E18, E19).
 */
class AdminPayoutTest extends TestCase
{
    use BuildsNotifications, BuildsPayouts, RefreshDatabase;

    private Provider $noura;

    private PayoutRequest $payout;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->configurePayouts('15', 7);
        $this->paidCompletedBooking($this->noura);
        $this->at('2026-12-13T09:00:00Z');
        $this->saveAccount($this->noura)->assertOk();
        $this->requestPayout($this->noura, '500.00')->assertCreated();
        $this->payout = PayoutRequest::query()->sole();
        $this->officer = $this->payoutsOfficer();
    }

    private function markPaid(array $body = [], ?User $as = null)
    {
        return $this->asStaff($as ?? $this->officer)->postJson("/api/v1/admin/payouts/{$this->payout->id}/mark-paid",
            [...['transfer_reference' => 'TRF-2026-0001'], ...$body]);
    }

    private function reject(array $body = [], ?User $as = null)
    {
        return $this->asStaff($as ?? $this->officer)->postJson("/api/v1/admin/payouts/{$this->payout->id}/reject",
            [...['reason' => 'The account holder name does not match.'], ...$body]);
    }

    public function test_the_queue_lists_filters_and_searches(): void
    {
        $viewer = $this->staffWith('finance.view');

        $this->asStaff($viewer)->getJson('/api/v1/admin/payouts')->assertOk()
            ->assertJsonPath('data.0.reference', $this->payout->reference)
            ->assertJsonPath('data.0.amount.amount', '500.00')
            ->assertJsonPath('data.0.provider.id', $this->noura->id)
            ->assertJsonPath('data.0.provider.name', $this->noura->display_name)
            ->assertJsonPath('data.0.status', 'requested');
        $this->asStaff($viewer)->getJson('/api/v1/admin/payouts?filter[status]=paid')->assertOk()->assertJsonCount(0, 'data');
        $this->asStaff($viewer)->getJson('/api/v1/admin/payouts?q='.urlencode($this->payout->reference))->assertOk()->assertJsonCount(1, 'data');
        $this->asStaff($viewer)->getJson('/api/v1/admin/payouts?q=Noura')->assertOk()->assertJsonCount(1, 'data');
        $this->asStaff($viewer)->getJson('/api/v1/admin/payouts?q=nobody')->assertOk()->assertJsonCount(0, 'data');
        $this->asStaff($viewer)->getJson('/api/v1/admin/payouts?filter[from]=2026-12-14')->assertOk()->assertJsonCount(0, 'data');
        $this->asStaff($viewer)->getJson('/api/v1/admin/payouts?sort=bogus')->assertStatus(422);
    }

    public function test_staff_without_finance_permissions_cannot_see_the_queue(): void
    {
        $this->asStaff($this->staffWith('bookings.view'))->getJson('/api/v1/admin/payouts')->assertStatus(403);
        $this->asProvider($this->noura)->getJson('/api/v1/admin/payouts')->assertStatus(403);
    }

    public function test_the_detail_masks_the_iban_and_shows_actions(): void
    {
        $this->asStaff($this->officer)->getJson("/api/v1/admin/payouts/{$this->payout->id}")->assertOk()
            ->assertJsonPath('data.iban_masked', 'SA•• •••• •••• •••• •••• 7519')
            ->assertJsonMissingPath('data.iban')
            ->assertJsonPath('data.bank_name', 'Al Rajhi Bank')
            ->assertJsonPath('data.provider_available.amount', '350.00')
            ->assertJsonPath('data.history.0.event', 'payout_requested')
            ->assertJsonPath('data.actions.can_mark_paid', true)
            ->assertJsonPath('data.actions.can_view_iban', true);

        $this->asStaff($this->staffWith('finance.view'))->getJson("/api/v1/admin/payouts/{$this->payout->id}")->assertOk()
            ->assertJsonPath('data.actions.can_mark_paid', false)
            ->assertJsonPath('data.actions.can_reject', false)
            ->assertJsonPath('data.actions.can_view_iban', false);
    }

    public function test_the_full_iban_needs_payouts_process_and_is_audited(): void
    {
        $this->asStaff($this->staffWith('finance.view'))->getJson("/api/v1/admin/payouts/{$this->payout->id}/iban")->assertStatus(403);

        $this->asStaff($this->officer)->getJson("/api/v1/admin/payouts/{$this->payout->id}/iban")->assertOk()
            ->assertJsonPath('data.iban', self::IBAN);
        $this->assertTrue(AuditLog::query()->where('action', 'payout_iban_viewed')->where('actor_id', $this->officer->id)->exists());
    }

    public function test_mark_paid_records_the_transfer_and_notifies_her(): void
    {
        $this->markPaid(['internal_note' => 'Sent from the main account'])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.transfer_reference', 'TRF-2026-0001')
            ->assertJsonPath('data.processed_by.id', $this->officer->id)
            ->assertJsonPath('data.internal_note', 'Sent from the main account')
            ->assertJsonPath('data.actions.can_mark_paid', false);

        $this->payout->refresh();
        $this->assertSame(PayoutRequestStatus::Paid, $this->payout->status);
        $this->assertNull($this->payout->open_provider_id);
        $audit = AuditLog::query()->where('action', 'payout_paid')->sole();
        $this->assertSame($this->officer->id, $audit->actor_id);
        $this->assertSame('TRF-2026-0001', $audit->new['transfer_reference']);
        $this->assertContains('payout_paid', $this->events($this->noura->user_id));

        $this->asProvider($this->noura)->getJson("/api/v1/provider/payout-requests/{$this->payout->id}")->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.transfer_reference', 'TRF-2026-0001')
            ->assertJsonMissingPath('data.internal_note');
        $this->earnings($this->noura)->assertJsonPath('data.paid_out.amount', '500.00')
            ->assertJsonPath('data.in_payout.amount', '0.00')
            ->assertJsonPath('data.available.amount', '350.00');
    }

    public function test_mark_paid_is_idempotent_and_final(): void
    {
        $this->markPaid()->assertOk();
        $this->markPaid()->assertOk();
        $this->markPaid(['transfer_reference' => 'TRF-OTHER'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->reject()->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->assertSame(1, AuditLog::query()->where('action', 'payout_paid')->count());
    }

    public function test_a_transfer_reference_is_used_once(): void
    {
        $this->markPaid()->assertOk();
        $this->requestPayout($this->noura, '100.00')->assertCreated();
        $second = PayoutRequest::query()->latest('id')->first();

        $this->asStaff($this->officer)->postJson("/api/v1/admin/payouts/{$second->id}/mark-paid", ['transfer_reference' => 'TRF-2026-0001'])
            ->assertStatus(422)->assertJsonValidationErrors('transfer_reference');
    }

    public function test_reject_returns_the_money_and_notifies_her(): void
    {
        $this->reject()->assertOk()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The account holder name does not match.');

        $reversal = ProviderLedgerEntry::query()->where('type', LedgerEntryType::PayoutReversal)->sole();
        $this->assertSame(50000, $reversal->amount_cents);
        $this->assertSame('payout_reversal:'.$this->payout->id, $reversal->entry_key);
        $this->assertSame('The account holder name does not match.', AuditLog::query()->where('action', 'payout_rejected')->sole()->reason);
        $this->assertContains('payout_rejected', $this->events($this->noura->user_id));
        $this->earnings($this->noura)->assertJsonPath('data.available.amount', '850.00')
            ->assertJsonPath('data.can_request', true);
        $this->asProvider($this->noura)->getJson("/api/v1/provider/payout-requests/{$this->payout->id}")
            ->assertJsonPath('data.rejection_reason', 'The account holder name does not match.');
    }

    public function test_reject_is_idempotent(): void
    {
        $this->reject()->assertOk();
        $this->reject()->assertOk();
        $this->reject(['reason' => 'Another reason'])->assertStatus(409);

        $this->assertSame(1, ProviderLedgerEntry::query()->where('type', LedgerEntryType::PayoutReversal)->count());
    }

    public function test_bodies_are_validated(): void
    {
        $this->markPaid(['transfer_reference' => '  '])->assertStatus(422)->assertJsonValidationErrors('transfer_reference');
        $this->markPaid(['transfer_reference' => str_repeat('A', 65)])->assertStatus(422);
        $this->reject(['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_finance_view_cannot_act(): void
    {
        $viewer = $this->staffWith('finance.view');

        $this->markPaid([], $viewer)->assertStatus(403);
        $this->reject([], $viewer)->assertStatus(403);
        $this->assertSame(PayoutRequestStatus::Requested, $this->payout->refresh()->status);
    }

    public function test_an_unknown_payout_is_404(): void
    {
        $this->asStaff($this->officer)->getJson('/api/v1/admin/payouts/999999')->assertNotFound();
    }
}
