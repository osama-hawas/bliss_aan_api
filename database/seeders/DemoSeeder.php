<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\Audience;
use App\Enums\UploadContext;
use App\Enums\UserType;
use App\Models\Addon;
use App\Models\AppSetting;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

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
        $this->content();
        $this->uploadLimits();
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

    private function catalog(): void
    {
        $servicesByCategory = [
            'Event planning' => [['Full wedding planning', 'تنظيم حفل زفاف كامل', '15000.00']],
            'Venues' => [['Wedding hall', 'قاعة أفراح', '25000.00']],
            'Photography' => [['Event photography', 'تصوير مناسبة', '3500.00']],
            'Catering' => [['Buffet', 'بوفيه', '8000.00']],
            'Coffee servers' => [['Coffee service', 'خدمة صب القهوة', '1200.00']],
            'Bridesmaids' => [['Bridesmaids service', 'خدمة الوصيفات', '2000.00']],
            'Makeup' => [['Bridal makeup', 'مكياج عروس', '2500.00']],
            'Flowers & gifts' => [['Flower arrangements', 'تنسيق ورود', '1800.00']],
        ];

        $addon = Addon::query()->firstOrCreate(
            ['name->en' => '[DEMO] Extra hour'],
            ['name' => ['en' => '[DEMO] Extra hour', 'ar' => '[DEMO] ساعة إضافية']],
        );

        foreach ($servicesByCategory as $categoryName => $services) {
            $category = ServiceCategory::query()->where('name->en', $categoryName)->firstOrFail();

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

                if ($service->wasRecentlyCreated) {
                    $service->inclusions()->create(['text' => ['en' => '[DEMO] Setup and cleanup', 'ar' => '[DEMO] التجهيز والتنظيف'], 'sort' => 1]);
                    $service->addons()->syncWithoutDetaching([$addon->id]);
                }
            }
        }
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
}
