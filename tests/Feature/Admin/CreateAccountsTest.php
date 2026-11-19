<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\ProviderAccountType;
use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\AccountStatusLog;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Client and provider accounts added from the dashboard: same account as a self sign-up (OTP, no password); a provider
 * starts as a draft application she completes and submits for review.
 */
class CreateAccountsTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    public function test_staff_add_a_client_who_is_listed_with_history_and_audit(): void
    {
        $admin = $this->staff(AdminRole::AccountsOfficer);
        $city = City::query()->create(['name' => ['ar' => 'جدة', 'en' => 'Jeddah'], 'is_active' => true, 'sort' => 1]);

        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/clients', [
            'name' => ' Sara ', 'phone' => '0551234567', 'email' => 'sara@example.com', 'city_id' => $city->id,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.phone', '+966551234567')
            ->assertJsonPath('data.status', UserStatus::Active->value)
            ->json('data.id');

        $client = User::query()->findOrFail($id);
        $this->assertSame(UserType::Client, $client->type);
        $this->assertSame($city->id, $client->city_id);
        $this->assertTrue(AccountStatusLog::query()->where('user_id', $id)->where('action', 'client_created')->where('actor_id', $admin->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'client_created')->exists());
        $this->actingAsApi($admin)->getJson('/api/v1/admin/clients?q=Sara')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_staff_add_a_provider_as_a_draft_application(): void
    {
        $admin = $this->staff(AdminRole::AccountsOfficer);

        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/provider-applications', [
            'name' => 'Noura Salon', 'phone' => '+966 55 765 4321', 'account_type' => 'establishment',
        ])->assertCreated()->json('data.id');

        $provider = Provider::query()->with('user')->findOrFail($id);
        $this->assertSame(ProviderStatus::Draft, $provider->status);
        $this->assertSame(ProviderAccountType::Establishment, $provider->account_type);
        $this->assertSame('Noura Salon', $provider->display_name);
        $this->assertSame(UserType::Provider, $provider->user->type);
        $this->assertSame('+966557654321', $provider->user->phone);
        $this->assertTrue(AuditLog::query()->where('action', 'provider_created')->exists());
        $this->actingAsApi($admin)->getJson('/api/v1/admin/provider-applications?filter[status]=draft')
            ->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_the_phone_must_be_a_free_saudi_mobile(): void
    {
        $admin = $this->staff(AdminRole::AccountsOfficer);
        $taken = User::factory()->client()->create();

        $this->actingAsApi($admin)->postJson('/api/v1/admin/clients', ['name' => 'Sara', 'phone' => $taken->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/provider-applications', ['name' => 'Noura', 'phone' => $taken->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/clients', ['name' => 'Sara', 'phone' => '12345'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/clients', ['phone' => '0551234567'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/provider-applications', ['name' => 'N', 'phone' => '0551234568', 'account_type' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['account_type', 'name']);
    }

    public function test_it_needs_the_permission(): void
    {
        $support = $this->staff(AdminRole::DeliveryOfficer);

        $this->actingAsApi($support)->postJson('/api/v1/admin/clients', ['name' => 'Sara', 'phone' => '0551234567'])->assertForbidden();
        $this->actingAsApi($support)->postJson('/api/v1/admin/provider-applications', ['name' => 'Noura', 'phone' => '0551234567'])->assertForbidden();
        $this->assertFalse(User::query()->where('phone', '+966551234567')->exists());
    }
}
