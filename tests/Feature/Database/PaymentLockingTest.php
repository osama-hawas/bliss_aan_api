<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Enums\PayableType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\GatewayOutcome;
use App\Services\Payments\PaymentInstrument;
use App\Services\Payments\PaymentIntents;
use App\Services\Payments\PaymentRefunds;
use App\Services\Payments\PaymentStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 10 (slice-10-contract.md §9) with two real MySQL sessions. The other session plays the first of two racing
 * writers and holds its locks; this session must wait (1 s lock wait timeout → 1205), then decide on what it committed.
 */
class PaymentLockingTest extends TestCase
{
    use BuildsPayments, UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->freezeNow();
        $this->setUpMarket();
        $this->requirePayment();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function other()
    {
        return DB::connection('other_session');
    }

    private function create(Offer $offer, string $key, string $last4 = '4242'): array
    {
        return app(PaymentIntents::class)->create($this->client, PayableType::Offer, $offer,
            new PaymentInstrument(PaymentMethodType::Card, null, $this->cardToken($last4)), $key);
    }

    public function test_two_refunds_never_exceed_the_payment(): void
    {
        [$payment] = $this->create($this->offerFrom($this->matchingProvider(), null, '1000'), (string) Str::uuid());
        $booking = Booking::query()->sole();

        // The other session reserves 600 under the refund lock order: request → booking → payment.
        $this->other()->beginTransaction();
        $this->other()->select('select id from service_requests where id = ? for update', [$booking->service_request_id]);
        $this->other()->select('select id from bookings where id = ? for update', [$booking->id]);
        $this->other()->select('select id from payments where id = ? for update', [$payment->id]);
        $this->other()->table('refunds')->insert(['payment_id' => $payment->id, 'reference' => 'RF-9999999', 'kind' => 'staff', 'amount' => '600.00',
            'reason' => 'First', 'status' => 'in_progress', 'idempotency_key' => 'other', 'created_at' => now(), 'updated_at' => now()]);
        $this->other()->table('payments')->where('id', $payment->id)->update(['refunded_amount' => '600.00']);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(PaymentRefunds::class)->refund($payment, '600', RefundKind::Staff, null, 'Second', 'mine'));
        $this->other()->commit();

        try {
            app(PaymentRefunds::class)->refund($payment, '600', RefundKind::Staff, null, 'Second', 'mine');
            $this->fail('Expected REFUND_AMOUNT_EXCEEDED.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::RefundAmountExceeded, $e->errorCode);
        }
        $this->assertSame(1, Refund::query()->count());
    }

    public function test_a_webhook_and_the_charge_result_apply_once(): void
    {
        [$payment] = $this->create($this->offerFrom($this->matchingProvider()), (string) Str::uuid(), '3155');
        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);

        // The other session applies `succeeded` first and holds the payment row.
        $this->other()->beginTransaction();
        $this->other()->select('select id from payments where id = ? for update', [$payment->id]);
        $this->other()->table('payments')->where('id', $payment->id)->update(['status' => 'succeeded', 'paid_at' => now(), 'next_action_url' => null]);

        $this->shortLockWait();
        $outcome = new GatewayOutcome(PaymentStatus::Succeeded, $payment->gateway_reference);
        $this->assertWaits(fn () => app(PaymentStateMachine::class)->apply($payment, $outcome, 'webhook'));
        $this->other()->commit();

        app(PaymentStateMachine::class)->apply($payment, $outcome, 'webhook');
        $this->assertSame(0, DB::table('activity_events')->where('subject_type', $payment->getMorphClass())->where('subject_id', $payment->id)
            ->where('event', 'payment_succeeded')->count()); // this session found it settled and wrote nothing
    }

    public function test_same_key_creates_wait_then_replay(): void
    {
        $offer = $this->offerFrom($this->matchingProvider());
        $key = (string) Str::uuid();

        // The other session holds the payer lock while it inserts the attempt with this key.
        $this->other()->beginTransaction();
        $this->other()->select('select id from users where id = ? for update', [$this->client->id]);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->create($offer, $key));
        $this->other()->rollBack();

        [$first, $isNew] = $this->create($offer, $key);
        [$again, $replayed] = $this->create($offer, $key);
        $this->assertTrue($isNew);
        $this->assertFalse($replayed);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_two_successful_payments_on_one_offer_make_one_booking(): void
    {
        $offer = $this->offerFrom($this->matchingProvider());
        [$a] = $this->create($offer, (string) Str::uuid());
        // A second payment for the same offer (e.g. another device) also succeeds at the gateway.
        $b = Payment::query()->create([...collect($a->getAttributes())->except(['id', 'reference', 'gateway_reference', 'idempotency_key', 'created_at', 'updated_at'])->all(),
            'reference' => 'PY-9999999', 'idempotency_key' => 'other-device', 'status' => 'pending', 'fulfilment_status' => 'pending', 'refunded_amount' => '0.00']);
        $b = app(PaymentStateMachine::class)->apply($b, new GatewayOutcome(PaymentStatus::Succeeded, 'fake_ch_second'), 'webhook');
        app(PaymentIntents::class)->settle($b);

        $this->assertSame($a->id, Booking::query()->sole()->payment_id);
        $this->assertSame('ALREADY_BOOKED', $b->fresh()->fulfilment_error);
        $this->assertSame(RefundKind::AcceptanceFailed, $b->fresh()->refunds()->sole()->kind);
    }
}
