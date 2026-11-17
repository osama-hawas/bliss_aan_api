<?php

namespace Tests\Feature\Store;

use App\Enums\StoreOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b client orders: list, detail, tracking, reorder (`slice-9b-contract.md` §6.4, D211).
 */
class ClientStoreOrdersTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStore('25.00');
        $this->referencesEnding();
    }

    public function test_audiences(): void
    {
        $this->getJson(self::ORDERS_URL)->assertUnauthorized();
        $this->actingAsApi($this->storeOfficer())->getJson(self::ORDERS_URL)->assertForbidden();
    }

    public function test_list_tabs_and_ownership(): void
    {
        $client = $this->shopper();
        $active = $this->confirmedOrder($client);
        $done = $this->confirmedOrder($client);
        $done->forceFill(['status' => StoreOrderStatus::Delivered])->save();
        $this->confirmedOrder(); // someone else's

        $this->actingAsApi($client)->getJson(self::ORDERS_URL)->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($client)->getJson(self::ORDERS_URL.'?filter[status]=active')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->actingAsApi($client)->getJson(self::ORDERS_URL.'?filter[status]=done')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $done->id);
        $this->actingAsApi($client)->getJson(self::ORDERS_URL.'?filter[status]=nope')->assertStatus(422);
    }

    public function test_detail_shows_shipment_tracking_and_the_status_timeline_without_staff_data(): void
    {
        $client = $this->shopper();
        $order = $this->confirmedOrder($client);
        $staff = $this->storeOfficer();

        $this->actingAsApi($staff)->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/prepare", ['note' => 'Secret note'])->assertOk();
        $this->actingAsApi($staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/shipments", ['method' => 'carrier'])->assertCreated();

        $response = $this->actingAsApi($client)->getJson(self::ORDERS_URL."/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.shipment.method', 'carrier')
            ->assertJsonPath('data.shipment.status', 'in_transit')
            ->assertJsonPath('data.timeline.0.status', 'preparing')
            ->assertJsonPath('data.timeline.1.status', 'shipped')
            ->assertJsonPath('data.can_reorder', true);
        $this->assertNotNull($response->json('data.shipment.tracking_number'));
        $this->assertStringNotContainsString('Secret note', $response->getContent());
        $this->assertArrayNotHasKey('assigned_to', $response->json('data.shipment'));
    }

    public function test_reorder_copies_lines_at_current_stock_and_reports_skipped_ones(): void
    {
        $client = $this->shopper();
        $kept = $this->item('10.00', 10);
        $order = $this->confirmedOrder($client, $kept, 2);
        $gone = $this->item('5.00', 5);
        $order->items()->create([
            'product_variant_id' => $gone->id, 'product_id' => $gone->product_id, 'name' => ['en' => 'Gone', 'ar' => 'x'],
            'sku' => $gone->sku, 'unit_price' => '5.00', 'quantity' => 1, 'line_total' => '5.00',
        ]);
        $gone->update(['is_active' => false]);
        $kept->update(['price' => '11.00']);

        $this->actingAsApi($client)->postJson(self::ORDERS_URL."/{$order->id}/reorder")->assertOk()
            ->assertJsonPath('data.cart.items.0.variant_id', $kept->id)
            ->assertJsonPath('data.cart.items.0.quantity', 2)
            ->assertJsonPath('data.cart.items.0.unit_price.amount', '11.00')
            ->assertJsonPath('data.skipped.0.variant_id', $gone->id)
            ->assertJsonPath('data.skipped.0.reason', 'unavailable');

        // Repeating adds again but never above stock.
        $kept->update(['stock' => 3]);
        $this->actingAsApi($client)->postJson(self::ORDERS_URL."/{$order->id}/reorder")->assertOk()
            ->assertJsonPath('data.cart.items.0.quantity', 3)
            ->assertJsonPath('data.skipped.0.reason', 'reduced');
    }

    public function test_reorder_respects_the_max_per_line_and_refuses_unplaced_orders(): void
    {
        $client = $this->shopper();
        $variant = $this->item('10.00', 10);
        $order = $this->confirmedOrder($client, $variant, 4);
        $this->configureStore('25.00', null, 2);

        $this->actingAsApi($client)->postJson(self::ORDERS_URL."/{$order->id}/reorder")->assertOk()
            ->assertJsonPath('data.cart.items.0.quantity', 2);

        $pending = $this->bareOrder($client, StoreOrderStatus::PendingPayment);
        $this->actingAsApi($client)->postJson(self::ORDERS_URL."/{$pending->id}/reorder")->assertStatus(409);
        $this->actingAsApi($this->shopper())->postJson(self::ORDERS_URL."/{$order->id}/reorder")->assertNotFound();
    }
}
