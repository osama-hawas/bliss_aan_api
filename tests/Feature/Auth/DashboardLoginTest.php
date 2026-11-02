<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Enums\OtpPurpose;
use App\Models\User;
use App\Models\UserDevice;
use App\Routing\ApiRouteLoader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

/**
 * Admin staff sign in to the dashboard with phone + OTP (Q9). The store section is the same
 * login with store permissions (D10).
 */
class DashboardLoginTest extends TestCase
{
    use InteractsWithOtp, RefreshDatabase;

    private const PHONE = '+966512345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOtp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Route::middleware('api')->prefix('api/v1/_fx')->group(function () {
            ApiRouteLoader::load(base_path('tests/Fixtures/routes'));
        });
    }

    private function admin(AdminRole $role): User
    {
        $admin = User::factory()->admin()->create(['phone' => self::PHONE]);
        $admin->assignRole($role->value);

        return $admin;
    }

    private function login(string $app = 'dashboard')
    {
        $this->plantOtp(self::PHONE, OtpPurpose::Login);

        return $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'purpose' => 'login',
            'code' => '1234',
            'app' => $app,
            'device' => ['platform' => 'web', 'device_name' => 'Chrome'],
        ]);
    }

    public function test_admin_requests_a_login_code_from_the_dashboard(): void
    {
        $this->admin(AdminRole::SuperAdmin);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'purpose' => 'login', 'app' => 'dashboard'])
            ->assertOk();

        $this->assertNotNull($this->lastSentCode());
    }

    public function test_admin_login_returns_roles_and_permissions(): void
    {
        $admin = $this->admin(AdminRole::StoreContentOfficer);

        $response = $this->login()
            ->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.type', 'admin')
            ->assertJsonPath('data.user.roles', ['store_content_officer']);

        $this->assertContains('products.manage', $response->json('data.user.permissions'));
        $this->assertNotContains('clients.view', $response->json('data.user.permissions'));
        $this->assertSame('dashboard', UserDevice::query()->sole()->app->value);

        $this->withApiToken($response->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['store_content_officer']);
    }

    public function test_mobile_users_get_no_roles_in_me(): void
    {
        $this->actingAsApi(User::factory()->client()->create())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.roles')
            ->assertJsonMissingPath('data.permissions');
    }

    public function test_each_app_signs_in_only_its_own_user_type(): void
    {
        $this->admin(AdminRole::SuperAdmin);
        $this->login('client')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->login('provider')->assertForbidden();

        User::query()->forceDelete();
        User::factory()->client()->create(['phone' => self::PHONE]);
        $this->login()->assertForbidden();
        $this->assertSame(0, UserDevice::query()->count());
    }

    public function test_the_dashboard_cannot_register_accounts(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'purpose' => 'register', 'app' => 'dashboard'])
            ->assertForbidden();
    }

    public function test_suspended_admin_cannot_log_in(): void
    {
        $this->admin(AdminRole::SuperAdmin)->update(['status' => 'suspended', 'status_reason' => 'Left the company']);

        $this->login()->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_store_and_admin_sections_are_enforced_by_permission(): void
    {
        $store = $this->admin(AdminRole::StoreContentOfficer);
        $this->actingAsApi($store)->getJson('/api/v1/_fx/admin/store-ping')->assertOk();
        $this->actingAsApi($store)->getJson('/api/v1/_fx/admin/accounts-ping')
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $accounts = User::factory()->admin()->create();
        $accounts->assignRole(AdminRole::AccountsOfficer->value);
        $this->actingAsApi($accounts)->getJson('/api/v1/_fx/admin/accounts-ping')->assertOk();
        $this->actingAsApi($accounts)->getJson('/api/v1/_fx/admin/store-ping')->assertForbidden();

        $this->actingAsApi(User::factory()->client()->create())->getJson('/api/v1/_fx/admin/store-ping')->assertForbidden();
        $this->flushHeaders();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/_fx/admin/store-ping')->assertUnauthorized();
    }

    public function test_dashboard_session_refreshes_and_logs_out(): void
    {
        $this->admin(AdminRole::SuperAdmin);
        $token = $this->login()->json('data.access_token');

        $newToken = $this->withApiToken($token)->postJson('/api/v1/auth/token/refresh')
            ->assertOk()->json('data.access_token');
        $this->withApiToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->withApiToken($newToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withApiToken($newToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertNotNull(UserDevice::query()->sole()->revoked_at);
    }
}
