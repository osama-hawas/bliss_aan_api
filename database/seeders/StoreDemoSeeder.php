<?php

namespace Database\Seeders;

use App\Enums\MediaVisibility;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * FAKE local-development store data (Slice 9): [DEMO] product categories, products and variants.
 * Not the real catalogue, prices or stock. Never seeded in staging/production (called from DatabaseSeeder's local block).
 * Idempotent: rows are matched by SKU / name.
 */
class StoreDemoSeeder extends Seeder
{
    /**
     * Category [en, ar] => products [en, ar, base price, image, variants [sku, attributes|null, price, stock]].
     */
    private const STORE = [
        ['[DEMO] Giveaways', '[DEMO] توزيعات', [
            ['Wedding giveaways · 20 pcs', 'توزيعات زفاف · 20 قطعة', '120.00', 'gift3.jpg', [
                ['DEMO-GIV-20-GLD', ['color' => ['en' => 'Gold', 'ar' => 'ذهبي']], '120.00', 12],
                ['DEMO-GIV-20-SLV', ['color' => ['en' => 'Silver', 'ar' => 'فضي']], '120.00', 0],
            ]],
            ['Celebration dessert box', 'علبة حلويات للمناسبات', '95.00', 'dessert1.jpg', [
                ['DEMO-DES-BOX', null, '95.00', 20],
            ]],
        ]],
        ['[DEMO] Decor', '[DEMO] ديكور', [
            ['Oud & rose candle', 'شمعة عود وورد', '60.00', 'candles1.jpg', [
                ['DEMO-CAN-S', ['size' => ['en' => 'Small', 'ar' => 'صغير']], '60.00', 8],
                ['DEMO-CAN-L', ['size' => ['en' => 'Large', 'ar' => 'كبير']], '85.00', 3],
            ]],
        ]],
        ['[DEMO] Gifts', '[DEMO] هدايا', [
            ['Moments gift box', 'صندوق لحظات', '85.00', 'gift3.jpg', [
                ['DEMO-GIFT-BOX', null, '85.00', 15],
            ]],
        ]],
        ['[DEMO] Flowers', '[DEMO] ورد', [
            ['Dried flower bouquet', 'باقة ورد مجفف', '150.00', 'bouquet1.jpg', [
                ['DEMO-BQT-M-PNK', ['size' => ['en' => 'Medium', 'ar' => 'وسط'], 'color' => ['en' => 'Pink', 'ar' => 'وردي']], '150.00', 5],
                ['DEMO-BQT-L-BEG', ['size' => ['en' => 'Large', 'ar' => 'كبير'], 'color' => ['en' => 'Beige', 'ar' => 'بيج']], '190.00', 2],
            ]],
            ['Fresh flower arrangement', 'تنسيق ورد طبيعي', '220.00', 'flowers2.jpg', [
                ['DEMO-FLW-ARR', null, '220.00', 0],
            ]],
        ]],
    ];

    public function run(): void
    {
        foreach (self::STORE as $sort => [$en, $ar, $products]) {
            $category = ProductCategory::query()->where('name->en', $en)->first()
                ?? ProductCategory::query()->create(['name' => ['en' => $en, 'ar' => $ar], 'is_active' => true, 'sort' => $sort + 1]);

            foreach ($products as [$pEn, $pAr, $base, $image, $variants]) {
                $product = Product::query()->where('category_id', $category->id)->where('name->en', "[DEMO] {$pEn}")->first()
                    ?? Product::query()->create([
                        'category_id' => $category->id,
                        'name' => ['en' => "[DEMO] {$pEn}", 'ar' => "[DEMO] {$pAr}"],
                        'description' => ['en' => '[DEMO] Sample product for local development.', 'ar' => '[DEMO] منتج تجريبي للتطوير المحلي.'],
                        'base_price' => $base,
                        'is_active' => true,
                    ]);
                $this->image($product, $image);

                foreach ($variants as [$sku, $attributes, $price, $stock]) {
                    ProductVariant::query()->firstOrCreate(
                        ['sku' => $sku],
                        ['product_id' => $product->id, 'attributes' => $attributes, 'price' => $price, 'stock' => $stock, 'is_active' => true],
                    );
                }
            }
        }
    }

    private function image(Product $product, string $file): void
    {
        if ($product->media()->where('collection', 'image')->exists()) {
            return;
        }

        $path = "demo/{$file}";
        $disk = MediaVisibility::Public->disk();
        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, file_get_contents(database_path("seeders/demo-images/{$file}")));
        }

        Media::query()->create([
            'collection' => 'image',
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file,
            'mime' => 'image/jpeg',
            'size' => Storage::disk($disk)->size($path),
            'visibility' => MediaVisibility::Public,
        ])->attachable()->associate($product)->save();
    }
}
