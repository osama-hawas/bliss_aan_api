<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

class ChangePhoneTest extends TestCase
{
    use InteractsWithOtp, RefreshDatabase;

    private const NEW_PHONE = '+966598765432';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOtp();
    }

    public function test_code_is_sent_to_the_new_number(): void
    {
        $this->actingAsApi(User::factory()->client()->create())
            ->postJson('/api/v1/auth/change-phone/otp', ['phone' => '0598765432'])
            ->assertOk()
            ->assertJsonPath('data.length', 4);

        $this->assertSame(self::NEW_PHONE, $this->sentSms()[0]['phone']);
        $this->assertDatabaseHas('otp_codes', ['phone' => self::NEW_PHONE, 'purpose' => 'change_phone']);
    }

    public function test_phone_is_changed_with_a_valid_code(): void
    {
        $user = User::factory()->provider()->create(['phone' => '+966512345678']);
        $this->plantOtp(self::NEW_PHONE, OtpPurpose::ChangePhone);

        $this->actingAsApi($user)
            ->postJson('/api/v1/auth/change-phone', ['phone' => self::NEW_PHONE, 'code' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.phone', self::NEW_PHONE);

        $this->assertSame(self::NEW_PHONE, $user->fresh()->phone);
        $event = ActivityEvent::query()->where('event', 'phone_changed')->sole();
        $this->assertSame('+966512345678', $event->meta['old_phone']);
    }

    public function test_validation_failure(): void
    {
        $user = User::factory()->client()->create(['phone' => '+966512345678']);
        User::factory()->create(['phone' => self::NEW_PHONE]);

        $this->actingAsApi($user)->postJson('/api/v1/auth/change-phone', ['phone' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'code']);

        $this->actingAsApi($user)->postJson('/api/v1/auth/change-phone/otp', ['phone' => '0512345678'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        $this->actingAsApi($user)->postJson('/api/v1/auth/change-phone/otp', ['phone' => self::NEW_PHONE])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        $this->assertSame([], $this->sentSms());
    }

    public function test_wrong_user_type_is_forbidden(): void
    {
        $this->actingAsApi(User::factory()->admin()->create())
            ->postJson('/api/v1/auth/change-phone/otp', ['phone' => self::NEW_PHONE])
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_invalid_state(): void
    {
        $this->postJson('/api/v1/auth/change-phone/otp', ['phone' => self::NEW_PHONE])->assertUnauthorized();

        $this->actingAsApi(User::factory()->client()->suspended()->create())
            ->postJson('/api/v1/auth/change-phone/otp', ['phone' => self::NEW_PHONE])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        // A login code for the same number is not a phone-change code.
        $user = User::factory()->client()->create();
        $this->plantOtp(self::NEW_PHONE, OtpPurpose::Login);
        $this->actingAsApi($user)
            ->postJson('/api/v1/auth/change-phone', ['phone' => self::NEW_PHONE, 'code' => '1234'])
            ->assertUnprocessable()->assertJsonPath('code', 'OTP_INVALID');
    }
}
