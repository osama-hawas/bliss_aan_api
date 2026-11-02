<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

class VerifyOtpTest extends TestCase
{
    use InteractsWithOtp, RefreshDatabase;

    private const URL = '/api/v1/auth/otp/verify';

    private const PHONE = '+966512345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOtp(maxAttempts: 3);
    }

    private function login(array $overrides = [])
    {
        return $this->postJson(self::URL, [
            'phone' => '0512345678',
            'purpose' => 'login',
            'code' => '1234',
            'app' => 'client',
            ...$overrides,
        ]);
    }

    public function test_login_returns_a_session_bound_to_a_device(): void
    {
        $user = User::factory()->client()->create(['phone' => self::PHONE]);
        $this->plantOtp(self::PHONE, OtpPurpose::Login);

        $response = $this->login(['device' => ['platform' => 'ios', 'device_name' => 'iPhone 15', 'fcm_token' => 'fcm-1']])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.expires_in', 3600)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.phone', self::PHONE)
            ->assertJsonPath('data.user.type', 'client');

        $device = UserDevice::query()->sole();
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame('ios', $device->platform);
        $this->assertSame('fcm-1', $device->fcm_token);
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->withApiToken($response->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_register_purpose_returns_a_registration_token(): void
    {
        $this->plantOtp(self::PHONE, OtpPurpose::Register);

        $response = $this->login(['purpose' => 'register'])
            ->assertOk()
            ->assertJsonPath('data.expires_in', 300);

        $this->assertSame(64, strlen($response->json('data.registration_token')));
        $this->assertNotNull(OtpCode::query()->sole()->verified_at);
        $this->assertSame(0, UserDevice::query()->count());
    }

    public function test_validation_failure(): void
    {
        $this->postJson(self::URL, ['phone' => 'abc', 'code' => '12a4', 'purpose' => 'change_phone', 'device' => ['platform' => 'tv']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'code', 'purpose', 'app', 'device.platform']);
    }

    public function test_wrong_code_counts_attempts_and_burns_the_code_at_the_limit(): void
    {
        User::factory()->client()->create(['phone' => self::PHONE]);
        $otp = $this->plantOtp(self::PHONE, OtpPurpose::Login);

        $this->login(['code' => '0000'])->assertUnprocessable()->assertJsonPath('code', 'OTP_INVALID');
        $this->login(['code' => '0000'])->assertUnprocessable();
        $this->assertSame(2, $otp->fresh()->attempts);

        $this->login(['code' => '0000'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $this->assertNotNull($otp->fresh()->consumed_at);

        // The right code no longer works once burned.
        $this->login()->assertUnprocessable()->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_expired_code(): void
    {
        User::factory()->client()->create(['phone' => self::PHONE]);
        $this->plantOtp(self::PHONE, OtpPurpose::Login, attributes: ['expires_at' => now()->subSecond()]);

        $this->login()->assertUnprocessable()->assertJsonPath('code', 'OTP_EXPIRED');
    }

    public function test_code_cannot_be_used_twice_or_for_another_purpose(): void
    {
        User::factory()->client()->create(['phone' => self::PHONE]);
        $this->plantOtp(self::PHONE, OtpPurpose::Register);

        $this->login()->assertUnprocessable()->assertJsonPath('code', 'OTP_INVALID');

        $this->plantOtp(self::PHONE, OtpPurpose::Login);
        $this->login()->assertOk();
        $this->login()->assertUnprocessable()->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_wrong_user_type_is_forbidden(): void
    {
        User::factory()->provider()->create(['phone' => self::PHONE]);
        $otp = $this->plantOtp(self::PHONE, OtpPurpose::Login);

        $this->login()->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->assertNull($otp->fresh()->consumed_at);

        $this->login(['app' => 'provider'])->assertOk()->assertJsonPath('data.user.type', 'provider');
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        User::factory()->client()->suspended('Fraud')->create(['phone' => self::PHONE]);
        $this->plantOtp(self::PHONE, OtpPurpose::Login);

        $this->login()->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED')->assertJsonPath('errors.reason', 'Fraud');
    }

    public function test_register_purpose_for_a_taken_phone_is_rejected(): void
    {
        User::factory()->client()->create(['phone' => self::PHONE]);
        $this->plantOtp(self::PHONE, OtpPurpose::Register);

        $this->login(['purpose' => 'register'])->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    }
}
