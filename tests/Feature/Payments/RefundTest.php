<?php

namespace Tests\Feature\Payments;

use App\Enums\AdminRole;
use App\Enums\BookingPaymentStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Integrations\Payments\WebhookEvent;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsStaff;
use Tests\TestCase;

/**
 * Slice 10 (D187, slice-10-contract.md §4.2, §10.2): staff refunds, full or partial, with a reason and an audit row.
 */
class RefundTest extends TestCase
{
    use BuildsPayments, BuildsStaff, RefreshDatabase;

    private User $admin;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->requirePayment();
        $this->admin = $this->superAdmin();
        $this->payment = $this->paid($this->offerFrom($this->matchingProvider(), null, '1000'));
    }

    private function refund(array $body, ?string $key = null, ?User $as = null, ?Payment $payment = null): TestResponse
    {
        return $this->actingAsApi($as ?? $this->admin)->withHeader('Accept-Language', 'en')
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/admin/payments/'.($payment ?? $this->payment)->id.'/refunds', $body);
    }

    public function test_partial_then_full_refund(): void
    {
        $this->refund(['amount' => '250.50', 'reason' => 'Late arrival agreed with the client'])->assertCreated()
            ->assertJsonPath('data.kind', 'staff')->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.amount.amount', '250.50')->assertJsonPath('data.initiated_by.id', $this->admin->id)
            ->assertJsonPath('data.payment.id', $this->payment->id);
        $booking = Booking::query()->sole();
        $this->assertSame(BookingPaymentStatus::PartiallyRefunded, $booking->fresh()->payment_status);
        $this->assertSame('250.50', $this->payment->fresh()->refunded_amount);

        $audit = $this->lastAudit();
        $this->assertSame('payment_refunded', $audit->action);
        $this->assertSame('Late arrival agreed with the client', $audit->reason);
        $this->assertSame($this->admin->id, $audit->actor_id);

        $this->refund(['amount' => '749.50', 'reason' => 'Service cancelled'])->assertCreated();
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->fresh()->payment_status);

        $this->asClient()->getJson("/api/v1/client/payments/{$this->payment->id}")
            ->assertJsonPath('data.refund.status', 'full')->assertJsonPath('data.refund.refunded_amount.amount', '1000.00');
    }

    public function test_over_the_remainder_is_refused(): void
    {
        $this->refund(['amount' => '600', 'reason' => 'First part'])->assertCreated();
        $this->refund(['amount' => '400.01', 'reason' => 'Too much'])->assertStatus(422)->assertJsonPath('code', 'REFUND_AMOUNT_EXCEEDED');
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame('600.00', $this->payment->fresh()->refunded_amount);
    }

    public function test_only_a_succeeded_payment_can_be_refunded(): void
    {
        $failed = $this->paid($this->offerFrom($this->matchingProvider('Huda Studio')), '0002');
        $this->refund(['amount' => '1', 'reason' => 'Nope'], null, null, $failed)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_same_key_replays_and_another_amount_is_a_duplicate(): void
    {
        $key = (string) Str::uuid();
        $id = $this->refund(['amount' => '100', 'reason' => 'Goodwill'], $key)->assertCreated()->json('data.id');
        $this->refund(['amount' => '100', 'reason' => 'Goodwill'], $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->refund(['amount' => '200', 'reason' => 'Goodwill'], $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, Refund::query()->count());
    }

    public function test_a_failed_refund_releases_the_reserved_amount(): void
    {
        $payment = $this->paid($this->offerFrom($this->matchingProvider('Huda Studio'), null, '500'), '0051');
        $this->refund(['amount' => '500', 'reason' => 'Full refund'], null, null, $payment)->assertCreated()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'gateway_error');

        $this->assertSame('0.00', $payment->fresh()->refunded_amount);
        $this->assertSame(BookingPaymentStatus::Paid, Booking::query()->where('payment_id', $payment->id)->sole()->payment_status);
    }

    public function test_a_refund_webhook_settles_once(): void
    {
        $id = $this->refund(['amount' => '10', 'reason' => 'Small goodwill'])->json('data.id');
        $refund = Refund::query()->findOrFail($id);
        $this->webhook(WebhookEvent::REFUND_FAILED, $refund->gateway_reference)->assertOk()->assertJsonPath('data.result', 'applied');

        $this->assertSame(RefundStatus::Refunded, $refund->fresh()->status); // already settled, never moves back
    }

    public function test_validation_and_cancellation_link(): void
    {
        $this->asGuest()->actingAsApi($this->admin)->postJson("/api/v1/admin/payments/{$this->payment->id}/refunds", ['amount' => '1', 'reason' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->refund(['amount' => '0', 'reason' => 'Zero'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->refund(['amount' => '1.234', 'reason' => 'Too precise'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->refund(['amount' => '1'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->refund(['amount' => '1', 'reason' => 'ab'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->refund(['amount' => '1', 'reason' => 'Linked', 'cancellation_request_id' => 999])->assertStatus(422)->assertJsonValidationErrors('cancellation_request_id');

        $booking = Booking::query()->sole();
        $cancellation = CancellationRequest::query()->create([
            'reference' => 'CN-0000001', 'booking_id' => $booking->id, 'requester_id' => $this->client->id, 'requester_role' => 'client',
            'reason_code' => 'event_date_changed', 'reason_text' => 'Plans changed', 'status' => 'accepted',
        ]);
        $this->refund(['amount' => '1', 'reason' => 'Linked', 'cancellation_request_id' => $cancellation->id])->assertCreated()
            ->assertJsonPath('data.cancellation.id', $cancellation->id);
        $this->assertSame(0, Refund::query()->where('kind', RefundKind::AcceptanceFailed)->count());
    }

    public function test_permissions(): void
    {
        $viewer = User::factory()->admin()->create();
        Role::create(['name' => 'finance_viewer', 'guard_name' => 'api'])->givePermissionTo('finance.view');
        $viewer->assignRole('finance_viewer');

        $this->refund(['amount' => '1', 'reason' => 'No right'], null, $viewer)->assertStatus(403);
        $this->refund(['amount' => '1', 'reason' => 'No right'], null, $this->member(AdminRole::OperationsSupport))->assertStatus(403);
        $this->refund(['amount' => '1', 'reason' => 'No right'], null, $this->client)->assertStatus(403);
        $this->assertSame(0, Refund::query()->count());
    }
}
