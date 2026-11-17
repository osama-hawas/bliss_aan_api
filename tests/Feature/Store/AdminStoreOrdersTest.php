<?php

namespace Tests\Feature\Store;

use App\Enums\AdminRole;
use App\Enums\StoreOrderStatus;
use App\Models\AuditLog;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b staff order actions and shipments (`slice-9b-contract.md` §8.2, D201, D207, D211–D213).
 */
class AdminStoreOrdersTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStore('25.00');
        $this->referencesEnding();
    }

    private function ship($staff, int $orderId, array $body, ?string $key = null): TestResponse
    {
        return $this->actingAsApi($staff)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::ADMIN_ORDERS_URL."/{$orderId}/shipments", $body);
    }

    public function test_permissions(): void
    {
        $order = $this->confirmedOrder();
        $support = $this->member(AdminRole::OperationsSupport);

        $this->actingAsApi($support)->getJson(self::ADMIN_ORDERS_URL)->assertForbidden();
        $this->actingAsApi($this->shopper())->getJson(self::ADMIN_ORDERS_URL)->assertForbidden();
        $this->actingAsApi($this->deliveryOfficer())->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/prepare")->assertForbidden();
        $this->actingAsApi($this->storeOfficer())->getJson(self::ADMIN_ORDERS_URL)->assertOk();
    }

    public function test_list_hides_unpaid_attempts_by_default_and_filters(): void
    {
        $staff = $this->storeOfficer();
        $client = $this->shopper(['phone' => '+966511112222']);
        $placed = $this->confirmedOrder($client);
        $this->bareOrder($client, StoreOrderStatus::PendingPayment);
        $this->bareOrder($client, StoreOrderStatus::Expired);

        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $placed->id)
            ->assertJsonPath('data.0.client.phone', '+966511112222')
            ->assertJsonPath('data.0.items_count', 1);
        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL.'?filter[status]=all')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL.'?q=11112222')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL.'?q='.$placed->reference)->assertOk()->assertJsonPath('data.0.id', $placed->id);
    }

    public function test_prepare_then_internal_delivery_then_delivered(): void
    {
        $staff = $this->storeOfficer();
        $officer = $this->deliveryOfficer(['name' => 'Huda Driver']);
        $client = $this->shopper();
        $order = $this->confirmedOrder($client);

        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/prepare", ['note' => 'Packed'])->assertOk()
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.next_actions', ['ship', 'cancel']);
        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/prepare")->assertOk(); // repeat = no-op

        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL.'/delivery-officers')->assertOk()
            ->assertJsonFragment(['id' => $officer->id, 'name' => 'Huda Driver']);

        $this->ship($staff, $order->id, ['method' => 'internal'])->assertStatus(422)->assertJsonValidationErrors('assigned_admin_id');
        $this->ship($staff, $order->id, ['method' => 'internal', 'assigned_admin_id' => $staff->id])->assertStatus(422);
        $response = $this->ship($staff, $order->id, ['method' => 'internal', 'assigned_admin_id' => $officer->id])->assertCreated()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.shipments.0.status', 'in_transit')
            ->assertJsonPath('data.shipments.0.assigned_to.id', $officer->id)
            ->assertJsonPath('data.next_actions', ['mark_delivered', 'mark_failed']);
        $shipmentId = $response->json('data.shipments.0.id');

        $this->actingAsApi($officer)->getJson('/api/v1/admin/deliveries')->assertOk()
            ->assertJsonPath('data.0.id', $shipmentId)->assertJsonPath('data.0.reference', $order->reference);
        $this->actingAsApi($this->deliveryOfficer())->getJson('/api/v1/admin/deliveries')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($this->deliveryOfficer())->postJson("/api/v1/admin/shipments/{$shipmentId}/status", ['status' => 'delivered'])->assertNotFound();

        $this->actingAsApi($officer)->postJson("/api/v1/admin/shipments/{$shipmentId}/status", ['status' => 'delivered'])->assertOk()
            ->assertJsonPath('data.status', 'delivered');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $client->id, 'type' => 'store_order_delivered']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $client->id, 'type' => 'store_order_shipped']);
    }

    public function test_a_failed_delivery_returns_the_order_to_preparing_and_it_can_ship_again(): void
    {
        $staff = $this->storeOfficer();
        $order = $this->confirmedOrder();
        $shipmentId = $this->ship($staff, $order->id, ['method' => 'carrier'])->assertCreated()->json('data.shipments.0.id');

        $this->actingAsApi($staff)->postJson("/api/v1/admin/shipments/{$shipmentId}/status", ['status' => 'failed'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAsApi($staff)->postJson("/api/v1/admin/shipments/{$shipmentId}/status", ['status' => 'failed', 'reason' => 'Nobody home'])->assertOk()
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.shipments.0.failure_reason', 'Nobody home');

        $this->ship($staff, $order->id, ['method' => 'carrier'])->assertCreated()->assertJsonPath('data.status', 'shipped')
            ->assertJsonCount(2, 'data.shipments');
    }

    public function test_shipment_key_replays_and_one_live_shipment_at_a_time(): void
    {
        $staff = $this->storeOfficer();
        $order = $this->confirmedOrder();
        $key = (string) Str::uuid();

        $this->ship($staff, $order->id, ['method' => 'carrier'], $key)->assertCreated();
        $this->ship($staff, $order->id, ['method' => 'carrier'], $key)->assertCreated(); // replay: same shipment
        $this->assertSame(1, Shipment::query()->count());
        $this->ship($staff, $order->id, ['method' => 'carrier'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $other = $this->confirmedOrder();
        $this->ship($staff, $other->id, ['method' => 'carrier'], $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
    }

    public function test_carrier_failure_is_409_and_the_order_stays(): void
    {
        $this->referencesEnding('-FAIL');
        $staff = $this->storeOfficer();
        $order = $this->confirmedOrder();

        $this->ship($staff, $order->id, ['method' => 'carrier'])->assertStatus(409)->assertJsonPath('code', 'CARRIER_FAILED');
        $this->assertSame(StoreOrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame('failed', Shipment::query()->value('status')->value);
        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL."/{$order->id}")->assertOk()
            ->assertJsonPath('data.next_actions', ['prepare', 'ship', 'cancel']);
    }

    public function test_cancel_restores_stock_needs_a_reason_and_is_blocked_by_a_live_shipment(): void
    {
        $staff = $this->storeOfficer();
        $variant = $this->item('10.00', 5);
        $client = $this->shopper();
        $order = $this->confirmedOrder($client, $variant, 2);
        $this->assertSame(3, $variant->fresh()->stock);

        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/cancel")->assertStatus(422);
        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/cancel", ['reason' => 'Client asked by phone'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('meta.refundable', null); // no Slice 10 payment on this test order
        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame('store_order_cancelled', AuditLog::query()->latest('id')->value('action'));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $client->id, 'type' => 'store_order_cancelled']);
        $this->actingAsApi($client)->getJson(self::ORDERS_URL."/{$order->id}")->assertOk()
            ->assertJsonPath('data.cancel_reason', 'staff')
            ->assertJsonMissing(['cancel_reason' => 'Client asked by phone']);

        $shipped = $this->confirmedOrder();
        $this->ship($staff, $shipped->id, ['method' => 'carrier'])->assertCreated();
        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$shipped->id}/cancel", ['reason' => 'Too late'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_detail_shows_the_internal_timeline(): void
    {
        $staff = $this->storeOfficer(['name' => 'Reem Staff']);
        $order = $this->confirmedOrder();
        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/prepare", ['note' => 'Gift wrap'])->assertOk();

        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL."/{$order->id}")->assertOk()
            ->assertJsonFragment(['event' => 'order_note', 'note' => 'Gift wrap', 'visibility' => 'internal'])
            ->assertJsonFragment(['event' => 'order_placed', 'visibility' => 'internal'])
            ->assertJsonPath('data.recipient.name', 'Sara Client');
        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL.'/999999')->assertNotFound();
    }

    public function test_review_replaying_a_refused_carrier_shipment_stays_carrier_failed_and_another_body_is_a_duplicate(): void
    {
        $this->referencesEnding('-FAIL');
        $staff = $this->storeOfficer();
        $order = $this->confirmedOrder();
        $key = (string) Str::uuid();

        $this->ship($staff, $order->id, ['method' => 'carrier'], $key)->assertStatus(409)->assertJsonPath('code', 'CARRIER_FAILED');
        $this->ship($staff, $order->id, ['method' => 'carrier'], $key)->assertStatus(409)->assertJsonPath('code', 'CARRIER_FAILED');
        $this->ship($staff, $order->id, ['method' => 'internal', 'assigned_admin_id' => $this->deliveryOfficer()->id], $key)
            ->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_review_an_officer_gets_her_delivery_back_not_the_order(): void
    {
        $staff = $this->storeOfficer();
        $officer = $this->deliveryOfficer();
        $order = $this->confirmedOrder();
        $shipmentId = $this->ship($staff, $order->id, ['method' => 'internal', 'assigned_admin_id' => $officer->id])->json('data.shipments.0.id');

        $response = $this->actingAsApi($officer)->postJson("/api/v1/admin/shipments/{$shipmentId}/status", ['status' => 'delivered'])->assertOk()
            ->assertJsonPath('data.id', $shipmentId)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.reference', $order->reference);
        $this->assertNull($response->json('data.payment'));
        $this->assertNull($response->json('data.timeline'));
        $this->assertNull($response->json('data.client'));
    }

    public function test_review_a_stuck_pending_carrier_shipment_can_be_marked_failed_and_shipped_again(): void
    {
        $staff = $this->storeOfficer();
        $order = $this->confirmedOrder();
        $stuck = Shipment::query()->create([
            'store_order_id' => $order->id, 'method' => 'carrier', 'carrier' => 'fake', 'status' => 'pending',
            'request_key' => (string) Str::uuid(), 'attempts' => 3, 'created_by' => $staff->id,
        ]);

        $this->actingAsApi($staff)->getJson(self::ADMIN_ORDERS_URL."/{$order->id}")->assertOk()
            ->assertJsonPath('data.next_actions', ['prepare', 'retry_shipment', 'mark_failed']);
        $this->actingAsApi($staff)->postJson("/api/v1/admin/shipments/{$stuck->id}/status", ['status' => 'delivered'])->assertStatus(409);
        $this->actingAsApi($staff)->postJson("/api/v1/admin/shipments/{$stuck->id}/status", ['status' => 'failed', 'reason' => 'Carrier never answered'])->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.shipments.0.status', 'failed');

        $this->ship($staff, $order->id, ['method' => 'carrier'])->assertCreated()->assertJsonPath('data.status', 'shipped');
    }

    public function test_review_a_long_client_name_fits_the_recipient_snapshot(): void
    {
        $client = $this->shopper(['name' => str_repeat('ن', 80)]);
        $order = $this->pendingOrder($client, $this->item());

        $this->assertSame(60, mb_strlen($order->recipient_name));
    }
}
