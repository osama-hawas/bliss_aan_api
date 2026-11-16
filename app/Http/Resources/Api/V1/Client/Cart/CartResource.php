<?php

namespace App\Http\Resources\Api\V1\Client\Cart;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her cart (an empty one when she has none yet). Prices are live variant prices; `subtotal` sums the available lines
 * only. No discount, shipping fee or VAT: those are outside this scope (`slice-9-contract.md` §5).
 *
 * @property-read Cart|null $resource
 */
class CartResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $items = $this->resource?->items ?? collect();
        $subtotalCents = 0;

        $lines = $items->map(function (CartItem $item) use (&$subtotalCents) {
            $variant = $item->variant;
            $product = $variant->product;
            $available = $variant->isAvailable();
            $lineCents = self::cents($variant->price) * $item->quantity;
            if ($available) {
                $subtotalCents += $lineCents;
            }

            return [
                'variant_id' => $variant->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->category->name,
                'image_url' => $this->publicUrl($product->image),
                'attributes' => $variant->resolvedAttributes(),
                'unit_price' => $this->money($variant->price),
                'quantity' => $item->quantity,
                'line_total' => $this->money(self::amount($lineCents)),
                // Flags only; the line is never changed for her (open: Q-S9-1, Q-S9-2).
                'available' => $available,
                'in_stock' => $variant->inStock(),
                'exceeds_stock' => $item->quantity > $variant->stock,
            ];
        })->values();

        return [
            'items' => $lines,
            'count' => (int) $items->sum('quantity'),
            'subtotal' => $this->money(self::amount($subtotalCents)),
            'has_issues' => $lines->contains(fn (array $line) => ! $line['available'] || $line['exceeds_stock']),
        ];
    }

    private static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function amount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
