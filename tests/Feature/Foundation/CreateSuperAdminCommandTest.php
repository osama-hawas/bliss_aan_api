<?php

namespace Tests\Feature\Foundation;

use App\Enums\AdminRole;
use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_creates_a_super_admin_once(): void
    {
        $this->artisan('admin:create', ['phone' => '0551234567', 'name' => 'Owner'])->assertSuccessful();
        $this->artisan('admin:create', ['phone' => '0551234567', 'name' => 'Owner'])->assertSuccessful();

        $users = User::query()->where('phone', '+966551234567')->get();
        $this->assertCount(1, $users);
        $this->assertSame(UserType::Admin, $users[0]->type);
        $this->assertTrue($users[0]->hasRole(AdminRole::SuperAdmin->value));
    }

    public function test_it_refuses_bad_numbers_and_non_admin_accounts(): void
    {
        $this->artisan('admin:create', ['phone' => '12345', 'name' => 'X'])->assertFailed();

        User::query()->create(['phone' => '+966551234568', 'type' => UserType::Client, 'name' => 'Client']);
        $this->artisan('admin:create', ['phone' => '0551234568', 'name' => 'X'])->assertFailed();
        $this->assertFalse(User::query()->where('phone', '+966551234568')->first()->hasRole(AdminRole::SuperAdmin->value));
    }
}
