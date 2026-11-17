<?php

namespace Tests\Feature\Operations;

use App\Listeners\Operations\NotifyRefundIssued;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsNotifications;
use Tests\TestCase;

/**
 * slice-17-contract.md §5.4 (D263, E25): a settled staff refund notifies the paying client once. Needs Slice 10's
 * `payments` / `refunds` tables and `bookings.payment_id`: skipped until S10 is merged. Rows are inserted directly
 * (Slice 10's own tests cover how they are made); the listener is called the way Laravel calls it for `RefundSettled`.
 */
class RefundNotificationTest extends TestCase
{
    use BuildsBookings, BuildsNotifications, RefreshDatabase;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('refunds') || ! Schema::hasColumn('bookings', 'payment_id')) {
            $this->markTestSkipped('Slice 10 (payments) is not merged yet.');
        }
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    private ?int $paymentId = null;

    /**
     * One booking with one payment (a second booking would take the same slot); each call adds one refund row.
     */
    private function settled(string $kind, string $status): object
    {
        if ($this->paymentId === null) {
            $booking = $this->booking($this->noura);
            $this->paymentId = DB::table('payments')->insertGetId([
                'reference' => 'PY-'.random_int(1000000, 9999999), 'user_id' => $this->client->id, 'payable_type' => 'offer', 'payable_id' => $booking->offer_id,
                'purpose' => 'full', 'amount' => '1000.00', 'currency' => 'SAR', 'method' => 'card', 'gateway' => 'fake', 'status' => 'succeeded',
                'fulfilment_status' => 'fulfilled', 'refunded_amount' => '500.00', 'idempotency_key' => 'k-'.$booking->id, 'idempotency_fingerprint' => str_repeat('a', 64),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bookings')->where('id', $booking->id)->update(['payment_id' => $this->paymentId]);
        }
        $refundId = DB::table('refunds')->insertGetId([
            'payment_id' => $this->paymentId, 'reference' => 'RF-'.random_int(1000000, 9999999), 'kind' => $kind, 'amount' => '250.00',
            'reason' => 'Partial refund', 'status' => $status, 'idempotency_key' => 'r-'.$kind.'-'.$status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['refundId' => $refundId, 'paymentId' => $this->paymentId, 'status' => $status];
    }

    public function test_staff_refund_settled_notifies_the_client_once(): void
    {
        $event = $this->settled('staff', 'refunded');
        app(NotifyRefundIssued::class)->handle($event);
        app(NotifyRefundIssued::class)->handle($event); // at-least-once delivery: deduplicated

        $this->assertSame(1, DB::table('notifications')->where('type', 'refund_issued')->count());
        $this->assertSame('250.00 SAR', $this->row($this->client, 'refund_issued')->data['params']['amount']);
    }

    public function test_automatic_or_failed_refunds_send_nothing(): void
    {
        app(NotifyRefundIssued::class)->handle($this->settled('acceptance_failed', 'refunded'));
        app(NotifyRefundIssued::class)->handle($this->settled('staff', 'failed'));

        $this->assertSame(0, DB::table('notifications')->where('type', 'refund_issued')->count());
    }

    public function test_the_listener_is_bound_to_slice_10s_event(): void
    {
        $this->assertTrue(Event::hasListeners('App\\Events\\Payments\\RefundSettled'));
    }
}
