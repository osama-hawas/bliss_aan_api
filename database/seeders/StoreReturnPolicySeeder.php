<?php

namespace Database\Seeders;

use App\Enums\PolicyType;
use App\Models\PolicyVersion;
use Illuminate\Database\Seeder;

/**
 * S23 (OD37, D348): the first `store_return` policy version, published only when the type has no version yet. Later
 * versions are published by staff in Settings → Policies (Slice 20 `PolicyPublisher`, append-only). `:days` is replaced
 * with `store.return_window_days` when the text is shown. Plain text with `## ` headings and `- ` items (D163).
 */
class StoreReturnPolicySeeder extends Seeder
{
    public const CONTENT = [
        'ar' => "## سياسة الإرجاع\n"
            ."- يمكنك طلب إرجاع منتجات طلب تم توصيله خلال :days أيام من تاريخ التوصيل.\n"
            ."- اختاري المنتجات والكميات، وسبب الإرجاع، وأضيفي ملاحظة وحتى 3 صور.\n"
            ."- يراجع فريق Bliss aan الطلب ويقبله أو يرفضه مع ذكر السبب.\n"
            ."## الاسترداد\n"
            ."- بعد استلام المنتجات نسترد المبلغ المدفوع للمنتجات المرتجعة إلى وسيلة الدفع نفسها.\n"
            ."- يُخصم من المبلغ نصيب المنتجات من خصم كود الترويج إن وُجد.\n"
            ."- رسوم الشحن غير قابلة للاسترداد.\n",
        'en' => "## Return policy\n"
            ."- You can ask to return items of a delivered order within :days days of delivery.\n"
            ."- Choose the items and quantities and a reason, and add a note and up to 3 photos.\n"
            ."- The Bliss aan team reviews your request and approves it or explains why it was not accepted.\n"
            ."## Refunds\n"
            ."- Once we receive the items, we refund what you paid for them to the same payment method.\n"
            ."- The items' share of any promo code discount is deducted.\n"
            ."- Shipping fees are not refunded.\n",
    ];

    public function run(): void
    {
        if (PolicyVersion::query()->where('type', PolicyType::StoreReturn)->exists()) {
            return;
        }

        PolicyVersion::query()->create([
            'type' => PolicyType::StoreReturn,
            'version' => 1,
            'content' => self::CONTENT,
            'values' => ['window_days_setting' => 'store.return_window_days'],
            'effective_from' => now()->startOfDay(),
        ]);
    }
}
