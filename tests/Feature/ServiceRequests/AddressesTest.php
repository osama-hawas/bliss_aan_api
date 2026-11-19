<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Saved addresses (§6.2): CRUD, one default, soft delete keeps request snapshots.
 */
class AddressesTest extends TestCase
{
    use BuildsServiceRequests, RefreshDatabase;

    private const URL = '/api/v1/client/addresses';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
    }

    private function as(?User $user = null): static
    {
        return $this->actingAsApi($user ?? $this->client)->withHeader('Accept-Language', 'en');
    }

    private function add(array $overrides = []): int
    {
        return $this->as()->postJson(self::URL, [
            'city_id' => $this->riyadh->id, 'details' => 'Street 1', 'district' => 'Al Malqa', 'lat' => 24.7, 'lng' => 46.6, ...$overrides,
        ])->assertCreated()->json('data.id');
    }

    public function test_the_first_address_becomes_default_and_the_list_shows_it_first(): void
    {
        $first = $this->add(['label' => 'Home', 'recipient_phone' => '0551234567']);
        $second = $this->add(['label' => 'Mother']);

        $this->as()->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.0.city', ['id' => $this->riyadh->id, 'name' => 'Riyadh'])
            ->assertJsonPath('data.0.recipient_phone', '+966551234567')
            ->assertJsonPath('data.1.id', $second)
            ->assertJsonPath('data.1.is_default', false)
            ->assertJsonStructure(['data' => [['id', 'label', 'city', 'district', 'details', 'lat', 'lng', 'recipient_name', 'recipient_phone', 'is_default', 'created_at']]]);
    }

    public function test_set_default_switches_exactly_one(): void
    {
        $first = $this->add();
        $second = $this->add(['is_default' => true]);
        $this->assertSame([$second], Address::query()->where('is_default', true)->pluck('id')->all());

        $this->as()->postJson(self::URL."/{$first}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame([$first], Address::query()->where('is_default', true)->pluck('id')->all());
    }

    public function test_update_changes_only_sent_fields(): void
    {
        $id = $this->add(['label' => 'Home']);

        $this->as()->patchJson(self::URL."/{$id}", ['details' => 'Street 2'])
            ->assertOk()
            ->assertJsonPath('data.details', 'Street 2')
            ->assertJsonPath('data.label', 'Home');
    }

    public function test_validation(): void
    {
        $this->as()->postJson(self::URL, ['lat' => 100])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['city_id', 'details', 'lat', 'lng'], 'errors');

        $this->as()->postJson(self::URL, ['city_id' => $this->city('Old', 'قديمة', false)->id, 'details' => 'x', 'recipient_phone' => '123'])
            ->assertJsonValidationErrors(['city_id', 'recipient_phone'], 'errors');
    }

    public function test_deleting_the_default_leaves_none_and_keeps_request_snapshots(): void
    {
        $this->matchingProvider();
        $id = $this->add(['label' => 'Home']);
        $request = $this->created($this->openBody(['address' => null, 'address_id' => $id]));

        $this->as()->deleteJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data', null);

        $this->assertSoftDeleted('addresses', ['id' => $id]);
        $this->assertSame(0, Address::query()->where('is_default', true)->count());
        $this->as()->getJson(self::URL)->assertJsonCount(0, 'data');
        $this->assertSame('Home', $request->fresh()->address['label']);
        $this->as()->getJson("/api/v1/client/service-requests/{$request->id}")->assertJsonPath('data.address.details', 'Street 1');
    }

    public function test_another_clients_address_is_not_found(): void
    {
        $id = $this->add();
        $other = User::factory()->client()->create();

        $this->as($other)->patchJson(self::URL."/{$id}", ['details' => 'x'])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->as($other)->deleteJson(self::URL."/{$id}")->assertNotFound();
        $this->as($other)->postJson(self::URL."/{$id}/default")->assertNotFound();
    }

    public function test_wrong_user_type_is_forbidden(): void
    {
        $this->as(User::factory()->provider()->create())->getJson(self::URL)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson(self::URL)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    // QA BUG-006: a pin far from its city is refused; without a city center or the setting there is no limit.
    public function test_a_pin_outside_the_city_is_refused(): void
    {
        $this->riyadh->update(['center_lat' => 24.7136, 'center_lng' => 46.6753]);
        \App\Models\AppSetting::query()->updateOrCreate(['key' => 'requests.max_distance_from_city_km'], ['value' => 60]);
        app(\App\Services\Settings\AppSettings::class)->flush();

        $body = ['city_id' => $this->riyadh->id, 'details' => 'Street 1', 'lat' => 24.1658623, 'lng' => 47.282862]; // Al Kharj
        $this->as()->postJson(self::URL, $body)->assertUnprocessable()->assertJsonValidationErrors(['lat'], 'errors');
        $this->as()->postJson(self::URL, [...$body, 'lat' => 24.80, 'lng' => 46.61])->assertCreated(); // Al Malqa, Riyadh

        \App\Models\AppSetting::query()->where('key', 'requests.max_distance_from_city_km')->update(['value' => null]);
        app(\App\Services\Settings\AppSettings::class)->flush();
        $this->as()->postJson(self::URL, $body)->assertCreated();
    }
}
