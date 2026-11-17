<?php

namespace Tests\Feature\Store;

use App\Enums\StoreReturnStatus;
use App\Enums\UploadContext;
use App\Models\AppSetting;
use App\Models\StoreReturn;
use App\Services\Settings\AppSettings;
use App\Services\Settings\SettingCatalog;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\StoreReturnPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreReturns;
use Tests\TestCase;

/**
 * S23 client side (OD37, `slice-23-contract.md` §5): request, list, show, cancel; window, quantities, photos, money.
 */
class StoreReturnTest extends TestCase
{
    use BuildsStoreReturns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureReturns(7);
    }

    public function test_she_requests_a_return_of_a_delivered_order(): void
    {
        $order = $this->deliveredOrder();
        $item = $order->items->first();
        $photo = $this->returnPhoto($order->user);

        $this->requestReturn($order, [[$item->id, 1]], ['photo_media_ids' => [$photo->id]])->assertCreated()
            ->assertJsonPath('data.reference', 'RT-0000001')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.order.reference', $order->reference)
            ->assertJsonPath('data.reason_code', 'damaged')
            ->assertJsonPath('data.items.0.order_item_id', $item->id)
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.0.line_total.amount', '40.00')
            ->assertJsonPath('data.refund_amount.amount', '40.00')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.timeline.0.status', 'requested');

        $return = StoreReturn::query()->firstOrFail();
        $this->assertSame($return->id, $photo->fresh()->attachable_id);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $order->user_id, 'type' => 'store_return_requested']);

        // The order now shows the return and what is left to return.
        $this->actingAsApi($order->user)->getJson(self::ORDERS_URL."/{$order->id}")->assertOk()
            ->assertJsonPath('data.returns.0.reference', 'RT-0000001')
            ->assertJsonPath('data.return_options.window_days', 7)
            ->assertJsonPath('data.return_options.can_request', true)
            ->assertJsonPath('data.return_options.items.0.returnable_quantity', 1);
    }

    public function test_the_same_key_replays_and_another_body_is_a_duplicate(): void
    {
        $order = $this->deliveredOrder();
        $key = (string) Str::uuid();

        $id = $this->requestReturn($order, null, [], $key)->assertCreated()->json('data.id');
        $this->requestReturn($order, null, [], $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->requestReturn($order, null, ['note' => 'Something else'], $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, StoreReturn::query()->count());
    }

    public function test_only_a_delivered_order_inside_the_window_can_be_returned(): void
    {
        $order = $this->deliveredOrder();

        $order->forceFill(['status' => 'shipped'])->save();
        $this->requestReturn($order)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $order->forceFill(['status' => 'delivered', 'delivered_at' => now()->subDays(8)])->save();
        $this->requestReturn($order)->assertStatus(409)->assertJsonPath('code', 'RETURN_WINDOW_CLOSED');
        $this->actingAsApi($order->user)->getJson(self::ORDERS_URL."/{$order->id}")->assertJsonPath('data.return_options.can_request', false);

        $order->forceFill(['delivered_at' => now()->subDays(6)])->save();
        $this->requestReturn($order)->assertCreated();
    }

    public function test_no_window_setting_is_not_configured(): void
    {
        $order = $this->deliveredOrder();
        app(AppSettings::class)->set('store.return_window_days', null);

        $this->requestReturn($order)->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
    }

    public function test_quantities_are_limited_to_what_is_left_to_return(): void
    {
        $order = $this->deliveredOrder();
        $item = $order->items->first(); // 2 bought

        $this->requestReturn($order, [[$item->id, 3]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $first = $this->requestReturn($order, [[$item->id, 1]])->assertCreated()->json('data.id');
        $this->requestReturn($order, [[$item->id, 2]])->assertStatus(422);

        // A cancelled return frees its quantities again.
        $this->actingAsApi($order->user)->postJson(self::RETURNS_URL."/{$first}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->requestReturn($order, [[$item->id, 2]])->assertCreated();
    }

    public function test_items_must_belong_to_the_order(): void
    {
        $order = $this->deliveredOrder();
        $other = $this->deliveredOrder();

        $this->requestReturn($order, [[$other->items->first()->id, 1]])->assertStatus(422);
    }

    public function test_reason_note_and_at_most_three_own_photos(): void
    {
        $order = $this->deliveredOrder();
        $client = $order->user;

        $this->requestReturn($order, null, ['reason_code' => 'nope'])->assertStatus(422);
        $this->requestReturn($order, null, ['note' => ''])->assertStatus(422);
        $four = collect(range(1, 4))->map(fn () => $this->returnPhoto($client)->id)->all();
        $this->requestReturn($order, null, ['photo_media_ids' => $four])->assertStatus(422);

        $foreign = $this->returnPhoto($this->shopper());
        $this->requestReturn($order, null, ['photo_media_ids' => [$foreign->id]])->assertStatus(422);
        $wrongContext = $this->returnPhoto($client, UploadContext::ChatImage);
        $this->requestReturn($order, null, ['photo_media_ids' => [$wrongContext->id]])->assertStatus(422);

        $this->assertSame(0, StoreReturn::query()->count());
    }

    public function test_the_promo_discount_is_prorated_and_shipping_is_never_refunded(): void
    {
        $this->promo(['code' => 'TEN', 'type' => 'percent', 'value' => '10.00']);
        $a = $this->item('30.00', 10);
        $b = $this->item('10.00', 10);
        $order = $this->deliveredOrder(null, [[$a, 1], [$b, 1]], 'TEN'); // subtotal 40, discount 4, shipping 25
        [$lineA, $lineB] = [$order->items->firstWhere('product_variant_id', $a->id), $order->items->firstWhere('product_variant_id', $b->id)];

        $this->requestReturn($order, [[$lineA->id, 1]])->assertCreated()
            ->assertJsonPath('data.subtotal.amount', '30.00')
            ->assertJsonPath('data.discount.amount', '3.00')
            ->assertJsonPath('data.refund_amount.amount', '27.00');
        $this->requestReturn($order, [[$lineB->id, 1]])->assertCreated()
            ->assertJsonPath('data.discount.amount', '1.00')
            ->assertJsonPath('data.refund_amount.amount', '9.00');
        // Everything returned: 36.00 = products subtotal − discount; the 25.00 shipping stays paid.
        $this->assertSame('36.00', number_format((float) StoreReturn::query()->sum('refund_amount'), 2, '.', ''));
    }

    public function test_she_lists_shows_and_cancels_only_her_own_returns(): void
    {
        $order = $this->deliveredOrder();
        $id = $this->requestReturn($order)->assertCreated()->json('data.id');
        $stranger = $this->shopper();

        $this->actingAsApi($order->user)->getJson(self::RETURNS_URL)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($order->user)->getJson(self::RETURNS_URL.'?filter[status]=done')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($order->user)->getJson(self::ORDERS_URL."/{$order->id}/returns")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($order->user)->getJson(self::ORDERS_URL."/{$order->id}/returns/{$id}")->assertOk()->assertJsonPath('data.id', $id);

        $this->actingAsApi($stranger)->getJson(self::RETURNS_URL."/{$id}")->assertNotFound();
        $this->actingAsApi($stranger)->getJson(self::ORDERS_URL."/{$order->id}/returns")->assertNotFound();
        $this->actingAsApi($stranger)->postJson(self::RETURNS_URL."/{$id}/cancel")->assertNotFound();
        $this->actingAsApi($stranger)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ORDERS_URL."/{$order->id}/returns", ['reason_code' => 'damaged', 'note' => 'xyz', 'items' => [['order_item_id' => 1, 'quantity' => 1]]])
            ->assertNotFound();

        $this->actingAsApi($order->user)->postJson(self::ORDERS_URL."/{$order->id}/returns/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can_cancel', false);
        $this->actingAsApi($order->user)->postJson(self::RETURNS_URL."/{$id}/cancel")->assertOk(); // repeat: same answer
    }

    public function test_she_cannot_cancel_once_staff_approved(): void
    {
        $order = $this->deliveredOrder();
        $id = $this->requestReturn($order)->assertCreated()->json('data.id');
        StoreReturn::query()->whereKey($id)->update(['status' => StoreReturnStatus::Approved->value]);

        $this->actingAsApi($order->user)->postJson(self::RETURNS_URL."/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_the_return_policy_text_is_public_with_the_window_filled_in(): void
    {
        $this->getJson('/api/v1/shared/store/return-policy')->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');

        $this->seed(StoreReturnPolicySeeder::class);
        $text = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/store/return-policy')->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.window_days', 7)
            ->json('data.content');
        $this->assertStringContainsString('within 7 days', $text);
        $this->assertStringNotContainsString(':days', $text);
    }

    public function test_the_return_snapshots_the_policy_version(): void
    {
        $this->seed(StoreReturnPolicySeeder::class);
        $order = $this->deliveredOrder();
        $this->requestReturn($order)->assertCreated();

        $this->assertNotNull(StoreReturn::query()->value('policy_version_id'));
    }

    public function test_other_audiences_cannot_use_client_routes(): void
    {
        $this->actingAsApi($this->superAdmin())->getJson(self::RETURNS_URL)->assertForbidden();
    }

    public function test_the_window_is_an_editable_setting_seeded_at_seven_days(): void
    {
        $definition = SettingCatalog::all()['store.return_window_days'];
        $this->assertSame('integer', $definition['kind']->value);
        $this->assertSame(1, $definition['min']);
        $this->assertFalse($definition['fixed']);

        AppSetting::query()->where('key', 'store.return_window_days')->delete();
        $this->seed(AppSettingSeeder::class);
        $this->assertSame(7, AppSetting::query()->where('key', 'store.return_window_days')->value('value'));
        $this->assertNotNull(AppSetting::query()->where('key', 'uploads.store_return_photo')->value('value'));
    }
}
