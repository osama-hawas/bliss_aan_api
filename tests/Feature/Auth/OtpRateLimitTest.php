<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

/**
 * OTP abuse protection: limits per number and per IP, applied before any account lookup so a registered
 * and an unregistered number are limited the same way.
 */
class OtpRateLimitTest extends TestCase
{
    use InteractsWithOtp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No resend cooldown, so only the rate limit stops repeated requests.
        $this->configureOtp(cooldown: 0);
        config(['services.sms.rate_limits' => ['request_per_phone_per_hour' => 3, 'request_per_ip_per_hour' => 5, 'verify_per_ip_per_minute' => 4]]);
    }

    private function request(string $phone, string $purpose = 'register')
    {
        return $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone, 'purpose' => $purpose, 'app' => 'client']);
    }

    public function test_one_number_is_limited_however_it_is_written(): void
    {
        $this->request('0512345678')->assertOk();
        $this->request('+966512345678')->assertOk();
        $this->request('512345678')->assertOk();

        $this->request('0512345678')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $this->assertCount(3, $this->sentSms());
    }

    public function test_one_ip_is_limited_across_numbers(): void
    {
        foreach (range(1, 5) as $i) {
            $this->request("05123456{$i}0")->assertOk();
        }

        $this->request('0512345699')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $this->assertCount(5, $this->sentSms());
    }

    public function test_registered_and_unregistered_numbers_hit_the_same_limit(): void
    {
        User::factory()->client()->create(['phone' => '+966512345678']);
        config(['services.sms.rate_limits.request_per_ip_per_hour' => 100]);

        foreach (range(1, 3) as $i) {
            $this->request('0512345678', 'login')->assertOk();
            $this->request('0512345679', 'login')->assertNotFound();
        }

        $this->request('0512345678', 'login')->assertStatus(429);
        $this->request('0512345679', 'login')->assertStatus(429);
    }

    public function test_code_checks_are_limited_per_ip(): void
    {
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0512345678', 'code' => '0000', 'purpose' => 'login', 'app' => 'client'])
                ->assertStatus(404);
        }

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0512345678', 'code' => '0000', 'purpose' => 'login', 'app' => 'client'])
            ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }
}
