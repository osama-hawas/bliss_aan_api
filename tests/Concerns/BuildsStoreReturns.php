<?php

namespace Tests\Concerns;

use App\Enums\StoreOrderStatus;
use App\Enums\UploadContext;
use App\Models\Media;
use App\Models\ProductVariant;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Services\Store\Returns\StoreReturnReferenceGenerator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * S23 helpers: a delivered, really paid order (Slice 10 fake gateway), return requests through the API, return photos.
 */
trait BuildsStoreReturns
{
    use BuildsStoreOrders;

    protected const RETURNS_URL = '/api/v1/client/store-returns';

    protected const ADMIN_RETURNS_URL = '/api/v1/admin/store-returns';

    protected const CARD = 'tok_fake_visa_4242_1230';

    protected function configureReturns(?int $days = 7): void
    {
        $this->configureStore('25.00');
        $this->referencesEnding();
        app(AppSettings::class)->set('store.return_window_days', $days);
        app(AppSettings::class)->set(UploadContext::StoreReturnPhoto->settingKey(), ['mimes' => ['jpg', 'jpeg', 'png'], 'max_kb' => 5120]);
        $this->app->instance(StoreReturnReferenceGenerator::class, new class extends StoreReturnReferenceGenerator
        {
            private int $n = 0;

            public function next(): string
            {
                return 'RT-'.str_pad((string) ++$this->n, 7, '0', STR_PAD_LEFT);
            }
        });
    }

    /**
     * A delivered order paid through the Slice 10 core: one line per [variant, quantity].
     *
     * @param  list<array{0: ProductVariant, 1: int}>  $lines
     */
    protected function deliveredOrder(?User $client = null, array $lines = [], ?string $promo = null, string $token = self::CARD): StoreOrder
    {
        $client ??= $this->shopper();
        $lines = $lines ?: [[$this->item('40.00', 10), 2]];
        foreach ($lines as [$variant, $quantity]) {
            $this->inCart($client, $variant, $quantity);
        }
        $address = $this->addressOf($client);
        $total = $this->preview($client, array_filter(['address_id' => $address->id, 'promo_code' => $promo]))->assertOk()->json('data.total.amount');
        $id = $this->checkout($client, $address, $total, $promo)->assertCreated()->json('data.id');
        $this->actingAsApi($client)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ORDERS_URL."/{$id}/payments", ['method' => 'card', 'token' => $token])
            ->assertCreated()->assertJsonPath('meta.order.status', 'confirmed');

        $order = StoreOrder::query()->findOrFail($id);
        $order->forceFill(['status' => StoreOrderStatus::Delivered, 'delivered_at' => now()])->save();

        return $order->refresh()->load('items');
    }

    /** @param  list<array{0: int, 1: int}>|null  $items  [order item id, quantity]; default: every line in full */
    protected function requestReturn(StoreOrder $order, ?array $items = null, array $extra = [], ?string $key = null): TestResponse
    {
        $items ??= $order->items->map(fn ($item) => [$item->id, $item->quantity])->all();

        return $this->actingAsApi($order->user)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::ORDERS_URL."/{$order->id}/returns", [
                'reason_code' => 'damaged',
                'note' => 'The box arrived crushed.',
                'items' => array_map(fn ($row) => ['order_item_id' => $row[0], 'quantity' => $row[1]], $items),
                ...$extra,
            ]);
    }

    protected function returnPhoto(User $uploader, UploadContext $context = UploadContext::StoreReturnPhoto): Media
    {
        return Media::query()->create([
            'collection' => $context->value,
            'disk' => 'local',
            'path' => $context->value.'/'.Str::random(8).'.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1000,
            'visibility' => $context->visibility(),
            'uploaded_by' => $uploader->id,
        ]);
    }
}
