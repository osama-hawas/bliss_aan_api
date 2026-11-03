<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\Audience;
use App\Enums\MediaVisibility;
use App\Enums\ProviderAccountType;
use App\Enums\ProviderStatus;
use App\Enums\UploadContext;
use App\Enums\UserType;
use App\Models\Addon;
use App\Models\AppSetting;
use App\Models\Banner;
use App\Models\City;
use App\Models\Faq;
use App\Models\Media;
use App\Models\Page;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * FAKE local-development data. Not the real catalog, prices, texts or limits.
 * Everything here is marked [DEMO] and must never be seeded in staging/production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->users();
        $this->catalog();
        $this->providers();
        $this->content();
        $this->uploadLimits();
        $this->otpSettings();
    }

    private function users(): void
    {
        $admin = User::query()->firstOrCreate(
            ['phone' => '+966500000001'],
            ['type' => UserType::Admin, 'name' => '[DEMO] Super admin'],
        );
        $admin->syncRoles([AdminRole::SuperAdmin->value]);

        User::query()->firstOrCreate(
            ['phone' => '+966500000002'],
            ['type' => UserType::Client, 'name' => '[DEMO] Client'],
        );
    }

    /**
     * Category => [images, services [en, ar, guide price (null = not set)], add-ons].
     */
    private const CATALOG = [
        'Event planning' => [
            ['flowers2.jpg', 'candles1.jpg'],
            [['Full wedding planning', 'تنظيم حفل زفاف كامل', '15000.00'], ['Private party planning', 'تنسيق حفل خاص', '3000.00']],
        ],
        'Venues' => [
            ['hall3.jpg', 'hall5.jpg'],
            [['Wedding hall', 'قاعة أفراح', '25000.00'], ['Small hall', 'قاعة صغيرة', '6500.00']],
        ],
        'Photography' => [
            ['photo1.jpg', 'photo2.jpg'],
            [['Event photography', 'تصوير مناسبة', '3500.00'], ['Bride photo session', 'جلسة تصوير العروس', null]],
        ],
        'Catering' => [
            ['dessert3.jpg', 'dessert1.jpg'],
            [['Buffet', 'بوفيه', '8000.00'], ['Light hospitality', 'ضيافة خفيفة', '650.00']],
        ],
        'Coffee servers' => [
            ['coffee1.jpg', 'coffee4.jpg'],
            [['Coffee service', 'خدمة صب القهوة', '1200.00'], ['Coffee servers team', 'فريق صبابات قهوة', '2400.00']],
        ],
        'Bridesmaids' => [
            ['maids1.jpg', 'maids2.jpg'],
            [['Bridesmaids service', 'خدمة الوصيفات', '2000.00'], ['Eight bridesmaids', 'ثماني وصيفات', '2800.00']],
        ],
        'Makeup' => [
            ['makeup1.jpg', 'makeup4.jpg'],
            [['Bridal makeup', 'مكياج عروس', '2500.00'], ['Evening makeup', 'مكياج سهرة', '650.00']],
        ],
        'Flowers & gifts' => [
            ['bouquet1.jpg', 'gift3.jpg'],
            [['Flower arrangements', 'تنسيق ورود', '1800.00'], ['Guest flower favors', 'توزيعات ورد للضيفات', '1200.00']],
        ],
    ];

    /**
     * [en, ar, categories it applies to (null = all)].
     */
    private const ADDONS = [
        ['Extra hour', 'ساعة إضافية', null],
        ['Flower arch', 'قوس ورد', ['Event planning', 'Venues']],
        ['Extra photographer', 'مصورة إضافية', ['Photography']],
        ['Printed album', 'ألبوم مطبوع', ['Photography']],
    ];

    private function catalog(): void
    {
        foreach (self::CATALOG as $categoryName => [$images, $services]) {
            $category = ServiceCategory::query()->where('name->en', $categoryName)->firstOrFail();
            $this->demoImage($category, 'image', $images[0]);

            foreach ($services as $sort => [$en, $ar, $price]) {
                $service = Service::query()->firstOrCreate(
                    ['name->en' => "[DEMO] {$en}"],
                    [
                        'category_id' => $category->id,
                        'name' => ['en' => "[DEMO] {$en}", 'ar' => "[DEMO] {$ar}"],
                        'description' => ['en' => 'Demo description.', 'ar' => 'وصف تجريبي.'],
                        'guide_price' => $price,
                        'sort' => $sort + 1,
                    ],
                );
                $this->demoImage($service, 'image', $images[$sort % count($images)]);

                if ($service->inclusions()->doesntExist()) {
                    $service->inclusions()->create(['text' => ['en' => '[DEMO] Setup and cleanup', 'ar' => '[DEMO] التجهيز والتنظيف'], 'sort' => 1]);
                    $service->inclusions()->create(['text' => ['en' => '[DEMO] Consultation before the event', 'ar' => '[DEMO] جلسة استشارة قبل المناسبة'], 'sort' => 2]);
                    $service->inclusions()->create(['text' => ['en' => '[DEMO] Same-day coordination', 'ar' => '[DEMO] تنسيق في يوم المناسبة'], 'sort' => 3]);
                }
            }
        }

        foreach (self::ADDONS as [$en, $ar, $categories]) {
            $addon = Addon::query()->firstOrCreate(
                ['name->en' => "[DEMO] {$en}"],
                ['name' => ['en' => "[DEMO] {$en}", 'ar' => "[DEMO] {$ar}"]],
            );
            $services = Service::query()
                ->when($categories, fn ($query) => $query->whereHas('category', fn ($category) => $category->whereIn('name->en', $categories)))
                ->pluck('id');
            $addon->services()->syncWithoutDetaching($services);
        }
    }

    /**
     * [phone suffix, display name, account type, categories, cities, verified, featured,
     *  rating avg (null = no reviews), rating count, response minutes, custom price factor (null = guide price)].
     */
    private const PROVIDERS = [
        ['101', 'ليلك للتنسيق', 'establishment', ['Event planning'], ['Riyadh', 'Khobar'], true, true, '4.90', 128, 20, '1.10'],
        ['102', 'قاعة الماسة', 'establishment', ['Venues'], ['Riyadh'], true, true, '4.70', 86, 45, null],
        ['103', 'عدسة نورة', 'individual', ['Photography'], ['Riyadh', 'Jeddah'], false, true, '4.80', 64, 30, '0.90'],
        ['104', 'ضيافة الريم', 'establishment', ['Catering'], ['Jeddah'], true, false, '4.60', 40, 60, null],
        ['105', 'صبابات الأصالة', 'establishment', ['Coffee servers'], ['Riyadh', 'Dammam'], true, true, '4.80', 95, 15, '1.05'],
        ['106', 'وصيفات اللؤلؤ', 'establishment', ['Bridesmaids'], ['Riyadh'], false, false, '4.50', 30, 90, null],
        ['107', 'لمسة سارة', 'individual', ['Makeup'], ['Riyadh', 'Jeddah'], true, true, '4.90', 210, 10, '1.20'],
        ['108', 'ورد جوري', 'establishment', ['Flowers & gifts'], ['Riyadh'], false, false, '4.70', 52, 40, null],
        ['109', 'ندى للمناسبات', 'establishment', ['Event planning', 'Flowers & gifts'], ['Jeddah', 'Makkah'], false, false, '4.40', 18, 120, '0.85'],
        ['110', 'مصورة لمى', 'individual', ['Photography'], ['Dammam', 'Khobar'], false, false, null, 0, null, '1.00'],
    ];

    private function providers(): void
    {
        $images = collect(self::CATALOG)->map(fn (array $category) => $category[0]);

        foreach (self::PROVIDERS as $i => [$suffix, $name, $type, $categories, $cities, $verified, $featured, $rating, $ratingCount, $response, $factor]) {
            $user = User::query()->firstOrCreate(
                ['phone' => "+966500000{$suffix}"],
                ['type' => UserType::Provider, 'name' => "[DEMO] {$name}"],
            );
            $cityIds = City::query()->whereIn('name->en', $cities)->get()
                ->mapWithKeys(fn (City $city) => [$city->getTranslation('name', 'en') => $city->id]);

            $provider = Provider::query()->firstOrCreate(['user_id' => $user->id], [
                'account_type' => ProviderAccountType::from($type),
                'display_name' => "[DEMO] {$name}",
                'bio' => '[DEMO] نبذة تجريبية عن مقدمة الخدمة وخبرتها في المناسبات.',
                'city_id' => $cityIds[$cities[0]] ?? null,
                'started_year' => 2015 + $i,
                'status' => ProviderStatus::Active,
                'is_verified' => $verified,
                'is_featured' => $featured,
                'rating_avg' => $rating,
                'rating_count' => $ratingCount,
                'response_time_minutes' => $response,
                'activated_at' => now()->subDays(30 * ($i + 1)),
            ]);

            if (! $provider->wasRecentlyCreated) {
                continue;
            }

            foreach ($cityIds as $cityId) {
                $provider->coverageAreas()->create(['city_id' => $cityId]);
            }

            $categoryImages = $images->only($categories)->flatten();
            $this->demoImage($provider, 'avatar', $categoryImages[0]);
            $this->demoImage($provider, 'cover', $categoryImages[1] ?? $categoryImages[0]);

            foreach ($categoryImages as $sort => $file) {
                $provider->portfolioItems()->create([
                    'media_id' => $this->demoMedia($file, 'portfolio')->id,
                    'caption' => '[DEMO] من أعمالنا',
                    'sort' => $sort + 1,
                ]);
            }

            $services = Service::query()->whereHas('category', fn ($category) => $category->whereIn('name->en', $categories))->get();
            $offered = $services->map(fn (Service $service, int $index) => $provider->services()->create([
                'service_id' => $service->id,
                // Every other service follows the guide price, to exercise both paths.
                'custom_price' => $factor && $service->guide_price && $index % 2 === 0
                    ? number_format((float) $service->guide_price * (float) $factor, 2, '.', '')
                    : null,
                'at_provider_location' => in_array('Venues', $categories, true) || in_array('Makeup', $categories, true),
                'at_event_location' => ! in_array('Venues', $categories, true),
            ]));

            $addonIds = $services->load('addons')->pluck('addons')->flatten()->pluck('id')->unique();
            foreach ($addonIds as $index => $addonId) {
                $providerAddon = $provider->addons()->create(['addon_id' => $addonId, 'price' => (string) (250 + 150 * $index)]);
                $providerAddon->services()->sync(
                    $offered->filter(fn ($providerService) => $providerService->service->addons->contains('id', $addonId))->pluck('id'),
                );
            }
        }

        $featured = Provider::query()->where('is_featured', true)->orderBy('id')->first();
        $banner = Banner::query()->firstOrCreate(['title->en' => '[DEMO] Wedding season'], [
            'title' => ['en' => '[DEMO] Wedding season', 'ar' => '[DEMO] موسم الأعراس'],
            'app' => Audience::Client,
            'destination_type' => Provider::class,
            'destination_id' => $featured->id,
        ]);
        $this->demoImage($banner, 'image', 'flowers2.jpg');

        // One provider still under review: never visible to clients.
        $user = User::query()->firstOrCreate(['phone' => '+966500000199'], ['type' => UserType::Provider, 'name' => '[DEMO] Under review']);
        Provider::query()->firstOrCreate(['user_id' => $user->id], [
            'account_type' => ProviderAccountType::Individual,
            'display_name' => '[DEMO] Under review',
            'status' => ProviderStatus::Submitted,
        ]);
    }

    /**
     * Attaches a bundled demo photo once per collection.
     */
    private function demoImage(Model $attachable, string $collection, string $file): void
    {
        if ($attachable->media()->where('collection', $collection)->exists()) {
            return;
        }

        $this->demoMedia($file, $collection)->attachable()->associate($attachable)->save();
    }

    private function demoMedia(string $file, string $collection): Media
    {
        $path = "demo/{$file}";
        $disk = MediaVisibility::Public->disk();

        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, file_get_contents(database_path("seeders/demo-images/{$file}")));
        }

        return Media::query()->create([
            'collection' => $collection,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file,
            'mime' => 'image/jpeg',
            'size' => Storage::disk($disk)->size($path),
            'visibility' => MediaVisibility::Public,
        ]);
    }

    private function content(): void
    {
        foreach (Audience::cases() as $audience) {
            foreach (['terms' => ['Terms and conditions', 'الشروط والأحكام'], 'privacy' => ['Privacy policy', 'سياسة الخصوصية']] as $slug => [$en, $ar]) {
                Page::query()->firstOrCreate(
                    ['slug' => $slug, 'audience' => $audience],
                    [
                        'title' => ['en' => $en, 'ar' => $ar],
                        'body' => ['en' => '[DEMO] Placeholder text.', 'ar' => '[DEMO] نص مؤقت.'],
                    ],
                );
            }

            Faq::query()->firstOrCreate(
                ['audience' => $audience, 'question->en' => '[DEMO] How does it work?'],
                [
                    'question' => ['en' => '[DEMO] How does it work?', 'ar' => '[DEMO] كيف تعمل المنصة؟'],
                    'answer' => ['en' => '[DEMO] Placeholder answer.', 'ar' => '[DEMO] إجابة مؤقتة.'],
                    'sort' => 1,
                ],
            );
        }
    }

    /**
     * Development-only limits so uploads can be exercised locally. Real limits are undecided.
     */
    private function uploadLimits(): void
    {
        $image = ['mimes' => ['jpg', 'jpeg', 'png', 'webp'], 'max_kb' => 5120];
        $limits = [
            UploadContext::ProviderDocument->value => ['mimes' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_kb' => 10240],
            UploadContext::Portfolio->value => $image,
            UploadContext::ChatImage->value => $image,
            UploadContext::ChatFile->value => ['mimes' => ['pdf', 'doc', 'docx'], 'max_kb' => 10240],
            UploadContext::ChatVoice->value => ['mimes' => ['m4a', 'aac', 'mp3', 'ogg', 'wav'], 'max_kb' => 5120],
            UploadContext::BookingUpdate->value => $image,
            UploadContext::CatalogImage->value => $image,
            UploadContext::Banner->value => $image,
        ];

        foreach ($limits as $context => $value) {
            AppSetting::query()->where('key', "uploads.{$context}")->whereNull('value')->update(['value' => json_encode($value)]);
        }
    }

    /**
     * [DEMO] OTP numbers for local testing only; the real values are undecided.
     */
    private function otpSettings(): void
    {
        $values = [
            'otp.expiry_seconds' => 300,
            'otp.max_attempts' => 5,
            'otp.resend_cooldown_seconds' => 60,
        ];

        foreach ($values as $key => $value) {
            AppSetting::query()->where('key', $key)->whereNull('value')->update(['value' => json_encode($value)]);
        }
    }
}
