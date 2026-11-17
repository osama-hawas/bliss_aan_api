<?php

namespace Tests\Feature\Store;

use App\Enums\AdminRole;
use App\Enums\RefundKind;
use App\Enums\StoreReturnStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Models\StoreReturn;
use App\Services\Payments\PaymentRefunds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreReturns;
use Tests\TestCase;

/**
 * S23 staff side (OD37, `slice-23-contract.md` §6): list, detail, approve, reject, receive (restock + Slice 10 refund),
 * retry the refund; permissions; history, audit and notifications E55–E59.
 */
class AdminStoreReturnTest extends TestCase
{
    use BuildsStoreReturns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureReturns(7);
    }

    private function requested(?string $token = null): StoreReturn
    {
        $order = $token ? $this->deliveredOrder(token: $token) : $this->deliveredOrder();
        $id = $this->requestReturn($order, [[$order->items->first()->id, 1]])->assertCreated()->json('data.id');

        return StoreReturn::query()->findOrFail($id);
    }

    private function act(StoreReturn $return, string $action, array $body = [], $staff = null)
    {
        return $this->actingAsApi($staff ?? $this->superAdmin())->postJson(self::ADMIN_RETURNS_URL."/{$return->id}/{$action}", $body);
    }

    public function test_staff_list_and_detail(): void
    {
        $return = $this->requested();
        $staff = $this->storeOfficer();

        $this->actingAsApi($staff)->getJson(self::ADMIN_RETURNS_URL)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $return->reference)
            ->assertJsonPath('data.0.client.id', $return->user_id)
            ->assertJsonPath('data.0.items_count', 1);
        $this->actingAsApi($staff)->getJson(self::ADMIN_RETURNS_URL.'?filter[status]=open&q='.$return->reference)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($staff)->getJson(self::ADMIN_RETURNS_URL.'?filter[status]=refunded')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAsApi($staff)->getJson(self::ADMIN_RETURNS_URL."/{$return->id}")->assertOk()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.order.id', $return->store_order_id)
            ->assertJsonPath('data.payment.amount.amount', '105.00')
            ->assertJsonPath('data.next_actions', ['approve', 'reject'])
            ->assertJsonPath('data.timeline.0.event', 'return_requested');
        $this->actingAsApi($staff)->getJson(self::ADMIN_RETURNS_URL.'/999999')->assertNotFound();
    }

    public function test_approve_notifies_and_audits(): void
    {
        $return = $this->requested();

        $this->act($return, 'approve', ['note' => 'Courier pickup booked'], $this->storeOfficer())->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.next_actions', ['receive']);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $return->user_id, 'type' => 'store_return_approved']);
        $this->assertSame('store_return_approved', $this->lastAudit()->action);
        $this->assertDatabaseHas('activity_events', ['subject_type' => 'store_return', 'subject_id' => $return->id, 'event' => 'return_note', 'visibility' => 'internal']);
        // The internal note never reaches the client.
        $this->actingAsApi($return->user)->getJson(self::RETURNS_URL."/{$return->id}")->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonMissing(['note' => 'Courier pickup booked']);
        // Repeat = same answer; a reject now is refused.
        $this->act($return, 'approve')->assertOk();
        $this->act($return, 'reject', ['reason' => 'Too late'])->assertStatus(409);
    }

    public function test_reject_needs_a_reason_the_client_sees(): void
    {
        $return = $this->requested();

        $this->act($return, 'reject')->assertStatus(422);
        $this->act($return, 'reject', ['reason' => 'Used item, not returnable'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAsApi($return->user)->getJson(self::RETURNS_URL."/{$return->id}")->assertOk()
            ->assertJsonPath('data.reject_reason', 'Used item, not returnable');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $return->user_id, 'type' => 'store_return_rejected']);
        $this->act($return, 'receive')->assertStatus(409);
    }

    public function test_receive_restocks_and_refunds_the_paid_share(): void
    {
        $return = $this->requested();
        $variant = $return->items()->first()->variant;
        $stock = $variant->stock;
        $this->act($return, 'approve')->assertOk();

        $this->act($return, 'receive', ['restock' => true, 'note' => 'Box intact'])->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.restocked', true)
            ->assertJsonPath('data.refund.status', 'refunded')
            ->assertJsonPath('data.refund.amount.amount', '40.00')
            ->assertJsonPath('data.refund_attempts.0.status', 'refunded')
            ->assertJsonPath('data.next_actions', []);

        $this->assertSame($stock + 1, $variant->fresh()->stock);
        $movement = StockMovement::query()->latest('id')->first();
        $this->assertSame('store_return', $movement->source->value);
        $this->assertSame($return->store_order_id, $movement->store_order_id);

        $refund = Refund::query()->sole();
        $this->assertSame(RefundKind::StoreReturn, $refund->kind);
        $this->assertSame('40.00', (string) $refund->amount);
        $this->assertSame('40.00', (string) Payment::query()->sole()->refunded_amount);

        foreach (['store_return_received', 'store_return_refunded'] as $event) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $return->user_id, 'type' => $event]);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'store_return_received', 'subject_id' => $return->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_refunded']);

        $this->actingAsApi($return->user)->getJson(self::RETURNS_URL."/{$return->id}")->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.timeline.*.status', ['requested', 'approved', 'received', 'refunded']);

        // Repeating receive never refunds twice.
        $this->act($return, 'receive')->assertOk();
        $this->assertSame(1, Refund::query()->count());
    }

    public function test_receive_without_restock_leaves_stock(): void
    {
        $return = $this->requested();
        $variant = $return->items()->first()->variant;
        $stock = $variant->stock;
        $this->act($return, 'approve')->assertOk();

        $this->act($return, 'receive', ['restock' => false])->assertOk()->assertJsonPath('data.restocked', false);
        $this->assertSame($stock, $variant->fresh()->stock);
    }

    public function test_a_failed_refund_keeps_the_return_received_and_can_be_retried(): void
    {
        $return = $this->requested('tok_fake_visa_0051_1230'); // fake refunds fail for last4 0051
        $this->act($return, 'approve')->assertOk();

        $this->act($return, 'receive')->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.refund.status', 'failed')
            ->assertJsonPath('data.refund_attempts.0.status', 'failed')
            ->assertJsonPath('data.next_actions', ['retry_refund']);
        $this->assertNotNull($return->fresh()->refund_error);

        // Retry builds a new key (attempt 2); it fails again on this card, still one live attempt at a time.
        $this->act($return, 'refund')->assertOk()->assertJsonCount(2, 'data.refund_attempts');
        $this->assertSame(StoreReturnStatus::Received, $return->fresh()->status);
        $this->assertSame(0, Refund::query()->where('status', 'in_progress')->count());
    }

    public function test_the_refund_is_capped_at_what_the_payment_still_has(): void
    {
        $return = $this->requested();
        $payment = Payment::query()->sole();
        // Staff already refunded most of the order on the payment page (105.00 paid, 80.00 back).
        app(PaymentRefunds::class)->refund($payment, '80.00', RefundKind::Staff, $this->superAdmin(), 'Goodwill', (string) Str::uuid());
        $this->act($return, 'approve')->assertOk();

        $this->act($return, 'receive')->assertOk()->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund.amount.amount', '25.00');
    }

    public function test_permissions(): void
    {
        $return = $this->requested();
        $support = $this->member(AdminRole::OperationsSupport); // no orders.* permissions
        $store = $this->storeOfficer();                         // orders.view + update_status, no refunds.process

        $this->actingAsApi($support)->getJson(self::ADMIN_RETURNS_URL)->assertForbidden();
        $this->act($return, 'approve', [], $support)->assertForbidden();
        $this->act($return, 'approve', [], $store)->assertOk();
        $this->act($return, 'receive', [], $store)->assertForbidden();
        $this->actingAsApi($return->user)->getJson(self::ADMIN_RETURNS_URL)->assertForbidden();
    }
}
