<?php

namespace Tests\Feature\Store;

use App\Models\ShipmentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b carrier status sync (`slice-9b-contract.md` §8.3): the fake carrier reports `delivered` for references ending
 * in `-DELIVER`; each carrier status is stored once.
 */
class ShipmentSyncTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    public function test_sync_moves_delivered_carrier_shipments_once(): void
    {
        $this->configureStore('25.00');
        $this->referencesEnding('-DELIVER');
        $staff = $this->storeOfficer();
        $client = $this->shopper();
        $order = $this->confirmedOrder($client);

        $this->actingAsApi($staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/shipments", ['method' => 'carrier'])->assertCreated()
            ->assertJsonPath('data.status', 'shipped');

        $this->artisan('shipments:sync')->assertSuccessful();
        $this->artisan('shipments:sync')->assertSuccessful();

        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertSame(1, ShipmentEvent::query()->where('carrier_status', 'delivered')->count());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $client->id, 'type' => 'store_order_delivered']);
    }

    public function test_in_transit_shipments_stay(): void
    {
        $this->configureStore('25.00');
        $this->referencesEnding();
        $order = $this->confirmedOrder();
        $this->actingAsApi($this->storeOfficer())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ADMIN_ORDERS_URL."/{$order->id}/shipments", ['method' => 'carrier'])->assertCreated();

        $this->artisan('shipments:sync')->assertSuccessful();
        $this->assertSame('shipped', $order->fresh()->status->value);
    }
}
