<?php

namespace Tests\Feature\Shared;

use App\Enums\NotificationPreferenceKey;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/shared/notification-preferences';

    public function test_every_key_of_the_user_type_is_on_by_default(): void
    {
        $this->actingAsApi(User::factory()->client()->create())->withHeader('Accept-Language', 'en')->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0', ['key' => 'offers', 'label' => 'Offers', 'enabled' => true])
            ->assertJsonCount(4, 'data');

        $keys = collect($this->actingAsApi(User::factory()->provider()->create())->getJson(self::URL)->json('data'))->pluck('key');
        $this->assertSame(['new_requests', 'messages', 'promotions', 'reminders'], $keys->all());
    }

    public function test_partial_update(): void
    {
        $user = User::factory()->client()->create();

        $this->actingAsApi($user)->withHeader('Accept-Language', 'ar')->putJson(self::URL, ['preferences' => ['promotions' => false]])
            ->assertOk()
            ->assertJsonPath('data.2', ['key' => 'promotions', 'label' => 'العروض الترويجية', 'enabled' => false])
            ->assertJsonPath('data.0.enabled', true);

        $this->actingAsApi($user)->putJson(self::URL, ['preferences' => ['promotions' => true, 'offers' => false]])->assertOk();

        $service = app(NotificationPreferenceService::class);
        $this->assertTrue($service->isEnabled($user, NotificationPreferenceKey::Promotions));
        $this->assertFalse($service->isEnabled($user, NotificationPreferenceKey::Offers));
        $this->assertFalse($service->isEnabled($user, NotificationPreferenceKey::NewRequests));
    }

    public function test_validation_failure(): void
    {
        $client = User::factory()->client()->create();

        $this->actingAsApi($client)->putJson(self::URL, ['preferences' => ['new_requests' => false]])
            ->assertUnprocessable()->assertJsonValidationErrors(['preferences']);
        $this->actingAsApi($client)->putJson(self::URL, ['preferences' => ['offers' => 'maybe']])
            ->assertUnprocessable()->assertJsonValidationErrors(['preferences.offers']);
        $this->actingAsApi($client)->putJson(self::URL, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['preferences']);
    }

    public function test_wrong_user_type_is_forbidden(): void
    {
        $this->actingAsApi(User::factory()->admin()->create())->getJson(self::URL)->assertForbidden();
    }

    public function test_invalid_state(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAsApi(User::factory()->client()->suspended()->create())->putJson(self::URL, ['preferences' => ['offers' => false]])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }
}
