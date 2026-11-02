<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Enums\PolicyType;
use App\Models\ActivityEvent;
use App\Models\City;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Auth\OtpService;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use InteractsWithOtp, RefreshDatabase;

    private const URL = '/api/v1/auth/register';

    private const PHONE = '+966512345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOtp(expiry: 300);
        $this->seed(CitySeeder::class);
    }

    private function registrationToken(string $phone = self::PHONE): string
    {
        $this->plantOtp($phone, OtpPurpose::Register);

        return app(OtpService::class)->issueRegistrationToken($phone, '1234')['registration_token'];
    }

    private function register(array $overrides = [])
    {
        return $this->postJson(self::URL, [
            'registration_token' => $this->registrationToken(),
            'name' => 'Sara',
            'terms_accepted' => true,
            'app' => 'client',
            ...$overrides,
        ]);
    }

    public function test_client_registers_and_gets_a_session(): void
    {
        $city = City::query()->first();
        $terms = PolicyVersion::query()->create([
            'type' => PolicyType::Terms, 'version' => 1, 'content' => ['ar' => 'الشروط', 'en' => 'Terms'], 'effective_from' => now()->subDay(),
        ]);

        $response = $this->withHeader('Accept-Language', 'en')
            ->register(['email' => 'sara@example.com', 'city_id' => $city->id, 'device' => ['platform' => 'android']])
            ->assertCreated()
            ->assertJsonPath('message', 'Account created.')
            ->assertJsonPath('data.user.phone', self::PHONE)
            ->assertJsonPath('data.user.type', 'client')
            ->assertJsonPath('data.user.city.id', $city->id)
            ->assertJsonPath('data.user.locale', 'en');

        $user = User::query()->where('phone', self::PHONE)->sole();
        $this->assertSame('sara@example.com', $user->email);
        $this->assertSame(1, $user->devices()->count());

        $event = ActivityEvent::query()->where('event', 'registered')->sole();
        $this->assertSame($terms->id, $event->meta['terms_policy_version_id']);

        $this->withApiToken($response->json('data.access_token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_validation_failure(): void
    {
        $inactive = City::query()->create(['name' => ['ar' => 'مدينة', 'en' => 'Old city'], 'is_active' => false]);

        $this->register(['name' => '', 'terms_accepted' => false, 'email' => 'nope', 'city_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'terms_accepted', 'email', 'city_id']);

        $this->assertSame(0, User::query()->count());
    }

    public function test_wrong_app_is_forbidden(): void
    {
        $this->register(['app' => 'provider'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(0, User::query()->count());
    }

    public function test_unknown_or_used_registration_token(): void
    {
        $this->register(['registration_token' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonPath('code', 'OTP_INVALID');

        $token = $this->registrationToken();
        $this->register(['registration_token' => $token])->assertCreated();
        $this->register(['registration_token' => $token])
            ->assertUnprocessable()->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_expired_registration_token(): void
    {
        $token = $this->registrationToken();
        $this->travel(301)->seconds();

        $this->register(['registration_token' => $token])
            ->assertUnprocessable()->assertJsonPath('code', 'OTP_EXPIRED');
    }

    public function test_phone_registered_in_the_meantime_is_rejected(): void
    {
        $token = $this->registrationToken();
        User::factory()->client()->create(['phone' => self::PHONE]);

        $this->register(['registration_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    }
}
