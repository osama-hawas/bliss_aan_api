<?php

namespace App\Services\Store\Returns;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\StoreOrderStatus;
use App\Enums\StoreReturnReason;
use App\Enums\StoreReturnStatus;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreReturn;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\Media\MediaService;
use App\Services\Notifications\Notifier;
use App\Services\ServiceRequests\IdempotencyRace;
use App\Services\Store\StoreMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The client's side of a store return (S23, OD37; `slice-23-contract.md` §5): ask for one on a delivered order inside
 * the window, and cancel it while it is still `requested`.
 *
 * Create: replay by (user, Idempotency-Key) first, then one transaction in DeadlockRetry: lock her order → re-check the
 * key → status / window / quantities (purchased − held by her live returns, D343) → lock her photos → money (D345) →
 * insert with `RT-` retries → lines → photos → history → E55. The order row lock serialises two returns of one order,
 * so two parallel requests can never return more than she bought.
 */
class StoreReturnRequests
{
    public const MAX_PHOTOS = 3;

    private const MAX_REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private readonly ReturnPolicy $policy,
        private readonly StoreReturnReferenceGenerator $references,
        private readonly ActivityRecorder $activity,
        private readonly Notifier $notifier,
        private readonly MediaService $media,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * @param  array{reason_code: string, note: string, items: list<array{order_item_id: int, quantity: int}>, photo_media_ids?: list<int>}  $data
     * @return array{0: StoreReturn, 1: bool} the return and whether it was created now (false = replay)
     */
    public function create(User $client, int $orderId, string $key, array $data): array
    {
        $fingerprint = $this->fingerprint($orderId, $data);
        if ($existing = $this->replay($client, $key, $fingerprint)) {
            return [$existing, false];
        }

        try {
            $return = $this->retry->run(fn () => DB::transaction(
                fn () => $this->insert($client, $orderId, $key, $fingerprint, $data)
            ), 'store_return.create', ['store_order_id' => $orderId]);
        } catch (IdempotencyRace) {
            return [$this->replay($client, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
        }

        return [$return, true];
    }

    /** The client cancels her own return while it is `requested`. Repeating it answers the cancelled return. */
    public function cancel(User $client, int $returnId): StoreReturn
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($client, $returnId) {
            $return = StoreReturn::query()->whereKey($returnId)->where('user_id', $client->id)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::NotFound);
            if ($return->status === StoreReturnStatus::Cancelled) {
                return $return;
            }
            if (! $return->status->canMoveTo(StoreReturnStatus::Cancelled)) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $from = $return->status;
            $return->forceFill(['status' => StoreReturnStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->activity->record($return, 'return_cancelled', $from, StoreReturnStatus::Cancelled, $client);
            $this->activity->record($return->order()->first(), 'order_return_cancelled', actor: $client, visibility: ActivityVisibility::Internal,
                meta: ['store_return_id' => $return->id, 'reference' => $return->reference]);

            return $return;
        }), 'store_return.cancel', ['store_return_id' => $returnId]);
    }

    /**
     * What she may still return per order line: purchased − quantities held by her live returns (D343).
     *
     * @return array<int, int> order item id → returnable quantity
     */
    public static function returnable(StoreOrder $order): array
    {
        $held = DB::table('store_return_items')
            ->join('store_returns', 'store_returns.id', '=', 'store_return_items.store_return_id')
            ->where('store_returns.store_order_id', $order->id)
            ->whereIn('store_returns.status', StoreReturnStatus::holdingValues())
            ->groupBy('store_return_items.store_order_item_id')
            ->selectRaw('store_return_items.store_order_item_id as item_id, SUM(store_return_items.quantity) as held')
            ->pluck('held', 'item_id');

        $out = [];
        foreach ($order->items as $item) {
            $out[$item->id] = max(0, $item->quantity - (int) ($held[$item->id] ?? 0));
        }

        return $out;
    }

    private function insert(User $client, int $orderId, string $key, string $fingerprint, array $data): StoreReturn
    {
        $order = StoreOrder::query()->whereKey($orderId)->where('user_id', $client->id)->lockForUpdate()->first()
            ?? throw new ApiException(ErrorCode::NotFound);
        if (StoreReturn::query()->where('user_id', $client->id)->where('idempotency_key', $key)->exists()) {
            throw new IdempotencyRace;
        }
        if ($order->status !== StoreOrderStatus::Delivered || $order->payment_id === null) {
            throw new ApiException(ErrorCode::InvalidState, __('store.returns.not_returnable'));
        }
        $days = $this->policy->requiredWindowDays();
        $endsAt = $this->policy->windowEndsAt($order, $days);
        if ($endsAt === null || now()->greaterThan($endsAt)) {
            throw new ApiException(ErrorCode::ReturnWindowClosed);
        }

        $order->load('items');
        $lines = $this->lines($order, $data['items']);
        $photos = $this->photos($client, $data['photo_media_ids'] ?? []);

        $gross = $lines->sum(fn (array $line) => StoreMoney::cents((string) $line['item']->unit_price) * $line['quantity']);
        $previous = StoreReturn::query()->where('store_order_id', $order->id)->whereIn('status', StoreReturnStatus::holdingValues())->get(['subtotal', 'discount']);
        $money = ReturnMoney::compute(
            StoreMoney::cents((string) $order->subtotal),
            StoreMoney::cents((string) $order->discount),
            $previous->sum(fn (StoreReturn $r) => StoreMoney::cents((string) $r->subtotal)),
            $previous->sum(fn (StoreReturn $r) => StoreMoney::cents((string) $r->discount)),
            $gross,
        );

        $return = $this->insertRow([
            'store_order_id' => $order->id,
            'user_id' => $client->id,
            'status' => StoreReturnStatus::Requested,
            'reason_code' => StoreReturnReason::from($data['reason_code']),
            'note' => $data['note'],
            'policy_version_id' => $this->policy->current()?->id,
            'subtotal' => StoreMoney::amount($money['subtotal']),
            'discount' => StoreMoney::amount($money['discount']),
            'refund_amount' => StoreMoney::amount($money['refund']),
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
        ]);

        foreach ($lines as $line) {
            /** @var StoreOrderItem $item */
            $item = $line['item'];
            $return->items()->create([
                'store_order_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'name' => $item->name,
                'options' => $item->options,
                'sku' => $item->sku,
                'unit_price' => $item->unit_price,
                'quantity' => $line['quantity'],
                'line_total' => StoreMoney::amount(StoreMoney::cents((string) $item->unit_price) * $line['quantity']),
            ]);
        }
        foreach ($photos as $photo) {
            $this->media->attach($photo, $return, StoreReturn::PHOTOS);
        }

        $this->activity->record($return, 'return_requested', null, StoreReturnStatus::Requested, $client);
        $this->activity->record($order, 'order_return_requested', actor: $client, visibility: ActivityVisibility::Internal,
            meta: ['store_return_id' => $return->id, 'reference' => $return->reference]);
        // Sent with no actor on purpose: the confirmation is for her (Notifier skips the actor).
        $this->notifier->send($client->id, NotificationEvent::StoreReturnRequested, ['reference' => $return->reference],
            NotificationTargetType::StoreReturn, $return->id, "store_return:{$return->id}:requested");

        return $return;
    }

    /**
     * @param  list<array{order_item_id: int, quantity: int}>  $requested
     * @return Collection<int, array{item: StoreOrderItem, quantity: int}>
     */
    private function lines(StoreOrder $order, array $requested): Collection
    {
        $returnable = self::returnable($order);
        $items = $order->items->keyBy('id');
        $errors = [];
        $lines = collect();
        foreach (array_values($requested) as $i => $row) {
            $item = $items->get((int) $row['order_item_id']);
            if ($item === null) {
                $errors["items.{$i}.order_item_id"] = [__('store.returns.not_in_order')];

                continue;
            }
            $quantity = (int) $row['quantity'];
            if ($quantity > $returnable[$item->id]) {
                $errors["items.{$i}.quantity"] = [__('store.returns.quantity_max', ['max' => $returnable[$item->id]])];

                continue;
            }
            $lines->push(['item' => $item, 'quantity' => $quantity]);
        }
        if ($errors) {
            throw new ApiException(ErrorCode::ValidationFailed, null, $errors);
        }

        return $lines;
    }

    /**
     * Her own unattached `store_return_photo` uploads, locked so one file is never attached twice.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Media>
     */
    private function photos(User $client, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }
        $photos = Media::query()->whereKey($ids)
            ->where('uploaded_by', $client->id)
            ->where('collection', UploadContext::StoreReturnPhoto->value)
            ->whereNull('attachable_id')
            ->orderBy('id')->lockForUpdate()->get();
        if ($photos->count() !== count($ids)) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['photo_media_ids' => [__('store.returns.photo_invalid')]]);
        }

        return $photos;
    }

    private function insertRow(array $attributes): StoreReturn
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return StoreReturn::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'store_returns_user_id_idempotency_key_unique')) {
                    throw new IdempotencyRace;
                }
                if (! str_contains($e->getMessage(), 'store_returns_reference_unique')) {
                    throw $e;
                }
            }
        }

        throw new ApiException(ErrorCode::ServerError);
    }

    private function replay(User $client, string $key, string $fingerprint): ?StoreReturn
    {
        $existing = StoreReturn::query()->where('user_id', $client->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }

    private function fingerprint(int $orderId, array $data): string
    {
        $items = collect($data['items'])->map(fn ($row) => [(int) $row['order_item_id'], (int) $row['quantity']])->sortBy(fn ($r) => $r[0])->values()->all();
        $photos = array_map('intval', $data['photo_media_ids'] ?? []);
        sort($photos);

        return hash('sha256', json_encode([$orderId, $data['reason_code'], trim($data['note']), $items, $photos]));
    }
}
