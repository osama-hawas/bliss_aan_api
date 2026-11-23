<?php

namespace Database\Seeders;

use App\Enums\Audience;
use App\Models\OnboardingSlide;
use Illuminate\Database\Seeder;

/**
 * Slice 25 (D368): starting onboarding slides so both apps work out of the box. The client texts are the app's bundled
 * `onboarding_title_1..3` / `onboarding_text_1..3`. An app that already has any slide is never touched, so admin edits
 * survive re-seeding. No image: the app shows its bundled photo for a slide without one (D365).
 */
class OnboardingSlideSeeder extends Seeder
{
    public function run(): void
    {
        $slides = [
            Audience::Client->value => [
                [
                    ['ar' => 'مناسباتك، بتفاصيل تشبهك', 'en' => 'Your occasions, your details'],
                    ['ar' => 'قاعات، تنسيق، تصوير، ضيافة وقهوجيات. كل ما تحتاجينه في مكان واحد.', 'en' => 'Venues, styling, photography, hospitality and coffee hosts. Everything in one place.'],
                ],
                [
                    ['ar' => 'مقدمات خدمة موثوقات', 'en' => 'Providers you can trust'],
                    ['ar' => 'اختاري من نخبة المتخصصات، راجعي أعمالهن وتقييمات عميلات حقيقيات.', 'en' => 'Choose specialists, explore their work and read client reviews.'],
                ],
                [
                    ['ar' => 'طلب واحد، عروض متعددة', 'en' => 'One request, several possibilities'],
                    ['ar' => 'أرسلي تفاصيل مناسبتك، قارني العروض، وتواصلي مباشرة حتى يوم الحفل.', 'en' => 'Share your event details, compare quotes and stay connected until the celebration.'],
                ],
            ],
            Audience::Provider->value => [
                [
                    ['ar' => 'انضمي إلى بليس تيل', 'en' => 'Join Bliss Tale'],
                    ['ar' => 'سجّلي نشاطك وارفعي مستنداتك، وبعد المراجعة يظهر ملفك لعميلات يبحثن عن خدماتك.', 'en' => 'Register your business and upload your documents. Once reviewed, your profile reaches clients looking for your services.'],
                ],
                [
                    ['ar' => 'استقبلي الطلبات وأرسلي عروضك', 'en' => 'Receive requests, send offers'],
                    ['ar' => 'تصلك طلبات المناسبات في مدينتك، فأرسلي عرضك بسعرك وتفاصيلك وتابعي المحادثة مع العميلة.', 'en' => 'Occasion requests in your city come to you. Send your offer with your price and details, and chat with the client.'],
                ],
                [
                    ['ar' => 'احصلي على أرباحك', 'en' => 'Get paid'],
                    ['ar' => 'تابعي حجوزاتك وأرباحك في مكان واحد، واطلبي تحويل رصيدك المتاح إلى حسابك.', 'en' => 'Track your bookings and earnings in one place, and request a payout of your available balance.'],
                ],
            ],
        ];

        foreach ($slides as $app => $rows) {
            if (OnboardingSlide::query()->where('app', $app)->exists()) {
                continue;
            }
            foreach ($rows as $i => [$title, $text]) {
                OnboardingSlide::query()->create([
                    'app' => $app, 'title' => $title, 'text' => $text, 'sort' => $i + 1, 'is_active' => true,
                ]);
            }
        }
    }
}
