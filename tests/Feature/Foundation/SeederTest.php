<?php

namespace Tests\Feature\Foundation;

use App\Enums\AdminRole;
use App\Enums\Permission as PermissionName;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\Service;
use App\Models\ServiceCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_translations_resolve_by_locale(): void
    {
        $this->seed(DatabaseSeeder::class);
        $city = City::query()->orderBy('sort')->first();

        app()->setLocale('ar');
        $this->assertSame('الرياض', $city->name);
        app()->setLocale('en');
        $this->assertSame('Riyadh', $city->name);
    }
}
