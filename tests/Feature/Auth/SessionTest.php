<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Token;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function claims(string $token): array
    {
        return JWTAuth::manager()->decode(new Token($token), false)->toArray();
    }

    public function test_refresh_swaps_the_token_on_the_same_device(): void
    {
        $user = User::factory()->provider()->create();
        $old = $this->tokenFor($user);
        $device = UserDevice::query()->sole();

        $new = $this->withApiToken($old)->postJson('/api/v1/auth/token/refresh')
            ->assertOk()
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.expires_in', 3600)
            ->json('data.access_token');

        $this->assertSame('provider', $this->claims($new)['type']);
        $this->assertSame($this->claims($new)['jti'], $device->fresh()->jti);
        $this->assertSame(1, UserDevice::query()->count());

        $this->withApiToken($new)->getJson('/api/v1/auth/me')->assertOk();
        $this->withApiToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withApiToken($old)->postJson('/api/v1/auth/token/refresh')->assertUnauthorized();
    }

    public function test_expired_token_can_be_refreshed_inside_the_window_only(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->travel(2)->hours();
        $this->withApiToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()->assertJsonPath('code', 'TOKEN_EXPIRED');
        $fresh = $this->withApiToken($token)->postJson('/api/v1/auth/token/refresh')->assertOk()->json('data.access_token');

        $this->travel(15)->days();
        $this->withApiToken($fresh)->postJson('/api/v1/auth/token/refresh')
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');

        // Refresh mode must not leak into later requests.
        $this->withApiToken($fresh)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_refresh_requires_a_valid_open_session(): void
    {
        $this->postJson('/api/v1/auth/token/refresh')->assertUnauthorized();
        $this->withApiToken('not-a-jwt')->postJson('/api/v1/auth/token/refresh')->assertUnauthorized();

        $token = $this->tokenFor(User::factory()->create());
        UserDevice::query()->update(['revoked_at' => now()]);

        $this->withApiToken($token)->postJson('/api/v1/auth/token/refresh')->assertUnauthorized();
    }

    public function test_suspended_user_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $user->update(['status' => UserStatus::Suspended, 'status_reason' => 'Fraud']);

        $this->withApiToken($token)->postJson('/api/v1/auth/token/refresh')
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED')->assertJsonPath('errors.reason', 'Fraud');
    }

    public function test_logout_blacklists_the_token_and_closes_the_device(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        UserDevice::query()->update(['fcm_token' => 'fcm-1']);

        $this->withApiToken($token)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data', null);

        $device = UserDevice::query()->sole();
        $this->assertNotNull($device->revoked_at);
        $this->assertNull($device->fcm_token);
        $this->withApiToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withApiToken($token)->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_suspended_user_can_still_log_out(): void
    {
        $this->actingAsApi(User::factory()->suspended()->create())->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_me_returns_the_own_account_for_every_user_type(): void
    {
        $user = User::factory()->client()->create(['name' => 'Sara']);

        $this->actingAsApi($user)->withHeader('Accept-Language', 'ar')->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_label', 'نشط')
            ->assertJsonPath('data.city', null);

        $this->actingAsApi(User::factory()->admin()->create())->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.type', 'admin');
    }

    public function test_me_requires_an_active_account_and_session(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->actingAsApi(User::factory()->suspended('Fraud')->create())->getJson('/api/v1/auth/me')
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        // A valid JWT without a device session (e.g. issued outside login) is rejected.
        $this->withApiToken(JWTAuth::fromUser(User::factory()->create()))->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
