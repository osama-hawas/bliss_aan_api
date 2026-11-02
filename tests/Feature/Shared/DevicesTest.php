<?php

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_the_users_open_sessions(): void
    {
        $user = User::factory()->client()->create();
        $this->tokenFor($user);
        $this->tokenFor($user);
        UserDevice::query()->latest('id')->first()->update(['revoked_at' => now()]);
        $this->tokenFor(User::factory()->client()->create()); // someone else's

        $response = $this->actingAsApi($user)->getJson('/api/v1/shared/devices')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame([false, true], collect($response->json('data'))->pluck('is_current')->sort()->values()->all());
        $response->assertJsonMissingPath('data.0.jti')->assertJsonMissingPath('data.0.fcm_token');
    }

    public function test_logout_others_keeps_only_the_current_session(): void
    {
        $user = User::factory()->provider()->create();
        $other = $this->tokenFor($user);
        $current = $this->tokenFor($user);
        $stranger = $this->tokenFor(User::factory()->provider()->create());

        $this->withApiToken($current)->postJson('/api/v1/shared/devices/logout-others')
            ->assertOk()->assertJsonPath('data.revoked_count', 1);

        $this->withApiToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withApiToken($current)->getJson('/api/v1/auth/me')->assertOk();
        $this->withApiToken($stranger)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_fcm_token_is_set_on_the_current_device(): void
    {
        $user = User::factory()->client()->create();
        $this->tokenFor($user);

        $this->actingAsApi($user)->putJson('/api/v1/shared/devices/current/fcm-token', ['fcm_token' => 'fcm-abc'])
            ->assertOk()->assertJsonPath('data.is_current', true);

        $devices = UserDevice::query()->orderBy('id')->pluck('fcm_token')->all();
        $this->assertSame([null, 'fcm-abc'], $devices);

        $this->actingAsApi($user)->putJson('/api/v1/shared/devices/current/fcm-token', ['fcm_token' => null])->assertOk();
    }

    public function test_validation_failure(): void
    {
        $this->actingAsApi(User::factory()->client()->create())
            ->putJson('/api/v1/shared/devices/current/fcm-token', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['fcm_token']);
    }

    public function test_wrong_user_type_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAsApi($admin)->getJson('/api/v1/shared/devices')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->actingAsApi($admin)->postJson('/api/v1/shared/devices/logout-others')->assertForbidden();
    }

    public function test_invalid_state(): void
    {
        $this->getJson('/api/v1/shared/devices')->assertUnauthorized();

        $this->actingAsApi(User::factory()->client()->suspended()->create())
            ->postJson('/api/v1/shared/devices/logout-others')
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }
}
