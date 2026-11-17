<?php

namespace Tests\Feature\Foundation;

use App\Enums\AdminRole;
use App\Enums\Permission as PermissionName;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\DeclineReason;
use App\Models\DocumentType;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DeclineReasonSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_data_is_seeded_without_demo_content(): void
    {
        $this->seed(DatabaseSeeder::class); // APP_ENV=testing → no DemoSeeder

        $this->assertSame(6, City::count());
        $this->assertSame(8, ServiceCategory::count());
        $this->assertSame(0, Service::count());
        $this->assertSame(0, DocumentType::count());

        $this->assertEqualsCanonicalizing(AdminRole::values(), Role::pluck('name')->all());
        $this->assertTrue(Role::findByName('super_admin', 'api')->hasPermissionTo(PermissionName::RolesManage->value));
        $this->assertFalse(Role::findByName('delivery_officer', 'api')->hasPermissionTo(PermissionName::FinanceView->value));

        $this->assertSame(4, AppSetting::where('key', 'otp.length')->value('value'));
        $this->assertNull(AppSetting::where('key', 'uploads.chat_image')->value('value'));
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, City::count());
        $this->assertSame(5, Role::count());
    }

    public function test_demo_seeder_builds_browsable_providers_and_is_idempotent(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(11, Provider::count());
        $this->assertSame(10, Provider::query()->listed()->count());
        Storage::disk('public')->assertExists('demo/hall3.jpg');

        $this->getJson('/api/v1/shared/providers?per_page=50')->assertOk()->assertJsonPath('meta.total', 10);
        $this->getJson('/api/v1/shared/home')->assertOk()->assertJsonCount(1, 'data.banners');
    }

    public function test_translations_resolve_by_locale(): void
    {
        $this->seed(DatabaseSeeder::class);
        $city = City::query()->orderBy('sort')->first();

        app()->setLocale('ar');
        $this->assertSame('الرياض', $city->name);
        app()->setLocale('en');
        $this->assertSame('Riyadh', $city->name);
    }

    public function test_decline_reasons_carry_the_d243_wording(): void
    {
        $this->seed(DeclineReasonSeeder::class);

        $this->assertSame(7, DeclineReason::query()->count());
        foreach (DeclineReasonSeeder::REASONS as $code => $name) {
            $this->assertEquals($name, DeclineReason::query()->where('code', $code)->sole()->getTranslations('name'), $code);
        }
        $reason = DeclineReason::query()->where('code', 'fully_booked')->sole();
        app()->setLocale('ar');
        $this->assertSame('لدي حجز آخر في هذا الموعد', $reason->name);
        app()->setLocale('en');
        $this->assertSame('Already booked at this time', $reason->name);
    }

    public function test_decline_wording_replaces_only_the_old_proposed_text(): void
    {
        DeclineReason::query()->create(['code' => 'fully_booked', 'name' => DeclineReasonSeeder::PREVIOUS['fully_booked'], 'is_active' => false, 'sort' => 9]);
        $custom = ['ar' => 'نص مخصص', 'en' => 'Custom text'];
        DeclineReason::query()->create(['code' => 'short_notice', 'name' => $custom, 'is_active' => true, 'sort' => 6]);

        $this->seed(DeclineReasonSeeder::class);
        $this->seed(DeclineReasonSeeder::class);

        $old = DeclineReason::query()->where('code', 'fully_booked')->sole();
        $this->assertEquals(DeclineReasonSeeder::REASONS['fully_booked'], $old->getTranslations('name'));
        $this->assertFalse($old->is_active); // flag and sort untouched
        $this->assertSame(9, $old->sort);
        $this->assertEquals($custom, DeclineReason::query()->where('code', 'short_notice')->sole()->getTranslations('name'));
        $this->assertSame(7, DeclineReason::query()->count());
    }
}
