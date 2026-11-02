<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

class RequestOtpTest extends TestCase
{
    use InteractsWithOtp, RefreshDatabase;

    private const URL = '/api/v1/auth/otp/request';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOtp();
    }

    public function test_login_code_is_sent_to_the_normalized_phone(): void
    {
        User::factory()->client()->create(['phone' => '+966512345678']);

        $this->postJson(self::URL, ['phone' => '0512345678', 'purpose' => 'login', 'app' => 'client'])
            ->assertOk()
            ->assertJsonPath('data', ['expires_in' => 300, 'resend_in' => 60, 'length' => 4]);

        $this->assertSame('+966512345678', $this->sentSms()[0]['phone']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $this->lastSentCode());
        $this->assertDatabaseHas('otp_codes', ['phone' => '+966512345678', 'purpose' => 'login', 'consumed_at' => null]);
    }

    public function test_register_code_is_sent_for_a_new_phone_in_the_requested_language(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->postJson(self::URL, ['phone' => '+966512345678', 'purpose' => 'register', 'app' => 'client'])
            ->assertOk()
            ->assertJsonPath('message', 'Verification code sent.');

        $this->assertStringContainsString('verification code', $this->sentSms()[0]['message']);
    }

    public function test_validation_failure(): void
    {
        $this->postJson(self::URL, ['phone' => '12345', 'purpose' => 'change_phone'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['phone', 'purpose', 'app']);

        $this->postJson(self::URL, ['phone' => '0512345678', 'purpose' => 'login', 'app' => 'web'])
            ->assertJsonValidationErrors(['app']);
    }

    public function test_unknown_phone_cannot_request_a_login_code(): void
    {
        $this->postJson(self::URL, ['phone' => '0512345678', 'purpose' => 'login', 'app' => 'client'])
            ->assertNotFound()
            ->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');

        $this->assertSame([], $this->sentSms());
    }

    public function test_wrong_user_type_is_forbidden(): void
    {
        User::factory()->provider()->create(['phone' => '+966512345678']);
        User::factory()->admin()->create(['phone' => '+966512345679']);

        $this->postJson(self::URL, ['phone' => '0512345678', 'purpose' => 'login', 'app' => 'client'])
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson(self::URL, ['phone' => '0512345679', 'purpose' => 'login', 'app' => 'provider'])
            ->assertForbidden();

        // Provider accounts are created in Slice 3, not through client registration.
        $this->postJson(self::URL, ['phone' => '0512345670', 'purpose' => 'register', 'app' => 'provider'])
            ->assertForbidden();
    }

    public function test_suspended_account_gets_the_reason(): void
    {
        User::factory()->client()->suspended('Repeated no-shows')->create(['phone' => '+966512345678']);

        $this->postJson(self::URL, ['phone' => '0512345678', 'purpose' => 'login', 'app' => 'client'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED')
            ->assertJsonPath('errors.reason', 'Repeated no-shows');
    }

    public function test_registered_phone_cannot_request_a_register_code(): void
    {
        User::factory()->client()->create(['phone' => '+966512345678']);

        $this->postJson(self::URL, ['phone' => '0512345678', 'purpose' => 'register', 'app' => 'client'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_resend_is_blocked_during_the_cooldown_and_replaces_the_old_code(): void
    {
        User::factory()->client()->create(['phone' => '+966512345678']);
        $body = ['phone' => '0512345678', 'purpose' => 'login', 'app' => 'client'];

        $this->postJson(self::URL, $body)->assertOk();

        $this->travel(20)->seconds();
        $this->postJson(self::URL, $body)
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')
            ->assertJsonPath('errors.retry_after', 40);

        $this->travel(41)->seconds();
        $this->postJson(self::URL, $body)->assertOk();

        $this->assertSame(1, OtpCode::query()->whereNull('consumed_at')->count());
        $this->assertSame(2, OtpCode::query()->where('purpose', OtpPurpose::Login)->count());
    }

    public function test_not_configured_until_otp_settings_are_set(): void
    {
        app(AppSettings::class)->set('otp.expiry_seconds', null);

        $this->postJson(self::URL, ['phone' => '0512345678', 'purpose' => 'register', 'app' => 'client'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'NOT_CONFIGURED');

        $this->assertSame([], $this->sentSms());
    }
}
