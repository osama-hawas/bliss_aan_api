<?php

namespace Tests\Feature\Provider;

use App\Enums\ProviderStatus;
use App\Models\ActivityEvent;
use App\Models\City;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * D69: an active provider changes the cities she covers after activation, with the application's city rules.
 */
class CoverageTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/provider';

    private Provider $provider;

    private City $riyadh;

    private City $jeddah;

    private City $dammam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->application(ProviderStatus::Active);
        $this->riyadh = City::query()->sole();
        $this->jeddah = City::query()->create(['name' => ['ar' => 'جدة', 'en' => 'Jeddah'], 'is_active' => true, 'sort' => 2]);
        $this->dammam = City::query()->create(['name' => ['ar' => 'الدمام', 'en' => 'Dammam'], 'is_active' => true, 'sort' => 3]);
    }

    private function as(): static
    {
        return $this->actingAsApi($this->provider->user)->withHeader('Accept-Language', 'en');
    }

    public function test_the_profile_shows_her_coverage(): void
    {
        $this->as()->getJson(self::URL.'/profile')
            ->assertOk()
            ->assertJsonPath('data.coverage', [['id' => $this->riyadh->id, 'name' => 'Riyadh']]);
    }

    public function test_the_profile_shows_her_city_start_year_and_verification(): void
    {
        $this->provider->forceFill(['started_year' => 2019, 'is_verified' => true])->save();

        $this->as()->getJson(self::URL.'/profile')
            ->assertOk()
            ->assertJsonPath('data.city', ['id' => $this->riyadh->id, 'name' => 'Riyadh'])
            ->assertJsonPath('data.started_year', 2019)
            ->assertJsonPath('data.is_verified', true);
    }

    public function test_she_replaces_her_coverage(): void
    {
        $this->as()->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->dammam->id, $this->jeddah->id]])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.coverage', [['id' => $this->jeddah->id, 'name' => 'Jeddah'], ['id' => $this->dammam->id, 'name' => 'Dammam']]);

        $this->assertSame([$this->jeddah->id, $this->dammam->id], $this->provider->coverageAreas()->orderBy('city_id')->pluck('city_id')->all());
        $this->assertDatabaseMissing('provider_coverage_areas', ['provider_id' => $this->provider->id, 'city_id' => $this->riyadh->id]);
        $this->assertDatabaseMissing('provider_coverage_areas', ['provider_id' => $this->provider->id, 'district_id' => 1]);
        $this->assertTrue(ActivityEvent::query()->whereMorphedTo('subject', $this->provider)->where('event', 'coverage_updated')->exists());

        // Reopening shows the saved list.
        $this->as()->getJson(self::URL.'/profile')->assertJsonCount(2, 'data.coverage');
    }

    public function test_discovery_follows_the_new_coverage(): void
    {
        // Clients only see services with an execution place (D74).
        $this->provider->services()->update(['at_provider_location' => true]);

        $this->getJson('/api/v1/shared/providers?filter[city_id]='.$this->jeddah->id)->assertOk()->assertJsonPath('meta.total', 0);

        $this->as()->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->jeddah->id]])->assertOk();

        $this->getJson('/api/v1/shared/providers?filter[city_id]='.$this->jeddah->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/shared/providers?filter[city_id]='.$this->riyadh->id)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_validation_failure(): void
    {
        $inactive = City::query()->create(['name' => ['ar' => 'أبها', 'en' => 'Abha'], 'is_active' => false, 'sort' => 9]);

        foreach ([
            [[], 'coverage_city_ids'],
            [['coverage_city_ids' => []], 'coverage_city_ids'],
            [['coverage_city_ids' => 'riyadh'], 'coverage_city_ids'],
            [['coverage_city_ids' => [999999]], 'coverage_city_ids.0'],
            [['coverage_city_ids' => [$this->jeddah->id, $inactive->id]], 'coverage_city_ids.1'],
            [['coverage_city_ids' => [$this->jeddah->id, $this->jeddah->id]], 'coverage_city_ids.0'],
        ] as [$body, $field]) {
            $this->as()->putJson(self::URL.'/coverage', $body)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'VALIDATION_FAILED')
                ->assertJsonValidationErrors($field);
        }

        // Nothing changed.
        $this->assertSame([$this->riyadh->id], $this->provider->coverageAreas()->pluck('city_id')->all());
    }

    public function test_at_most_twenty_cities(): void
    {
        $ids = [$this->riyadh->id, $this->jeddah->id, $this->dammam->id];
        for ($i = 4; $i <= 21; $i++) {
            $ids[] = City::query()->create(['name' => ['ar' => "مدينة {$i}", 'en' => "City {$i}"], 'is_active' => true, 'sort' => $i])->id;
        }

        $this->as()->putJson(self::URL.'/coverage', ['coverage_city_ids' => $ids])
            ->assertUnprocessable()->assertJsonValidationErrors('coverage_city_ids')
            ->assertJsonMissingValidationErrors(['coverage_city_ids.0', 'coverage_city_ids.20']);

        $this->as()->putJson(self::URL.'/coverage', ['coverage_city_ids' => array_slice($ids, 0, 20)])
            ->assertOk()->assertJsonCount(20, 'data.coverage');
    }

    public function test_a_deactivated_city_she_covers_can_be_removed(): void
    {
        // She covers Riyadh and Jeddah; the admin later deactivates Jeddah.
        $this->provider->coverageAreas()->create(['city_id' => $this->jeddah->id]);
        $this->jeddah->update(['is_active' => false]);

        // The profile still lists it, so the app can show it and let her remove it.
        $this->as()->getJson(self::URL.'/profile')->assertOk()->assertJsonCount(2, 'data.coverage');

        // Keeping it is refused, with the error on that entry; nothing changes.
        $refused = $this->as()
            ->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->riyadh->id, $this->jeddah->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coverage_city_ids.1');
        $this->assertSame('One of the selected cities is not available.', $refused->json('errors')['coverage_city_ids.1'][0]);
        $this->assertCount(2, $this->provider->coverageAreas()->get());

        // Without it, the save goes through and it is gone; the city itself stays inactive.
        $this->as()->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->riyadh->id, $this->dammam->id]])
            ->assertOk()
            ->assertJsonPath('data.coverage', [['id' => $this->riyadh->id, 'name' => 'Riyadh'], ['id' => $this->dammam->id, 'name' => 'Dammam']]);
        $this->assertSame([$this->riyadh->id, $this->dammam->id], $this->provider->coverageAreas()->orderBy('city_id')->pluck('city_id')->all());
        $this->assertFalse($this->jeddah->refresh()->is_active);
        $this->as()->getJson(self::URL.'/profile')->assertJsonCount(2, 'data.coverage');
    }

    public function test_she_only_changes_her_own_coverage(): void
    {
        $other = $this->applicant();
        $other->provider->update(['status' => ProviderStatus::Active]);
        $other->provider->coverageAreas()->create(['city_id' => $this->riyadh->id]);

        $this->as()->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->jeddah->id]])->assertOk();

        $this->assertSame([$this->riyadh->id], $other->provider->coverageAreas()->pluck('city_id')->all());
    }

    public function test_only_an_active_provider_and_only_providers(): void
    {
        $this->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->jeddah->id]])->assertUnauthorized();

        $draft = $this->applicant();
        $this->actingAsApi($draft)->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->jeddah->id]])
            ->assertConflict()->assertJsonPath('code', 'INVALID_STATE');

        $suspended = $this->applicant();
        $suspended->provider->update(['status' => ProviderStatus::Suspended]);
        $this->actingAsApi($suspended)->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->jeddah->id]])
            ->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
        $this->assertSame([], $suspended->provider->coverageAreas()->pluck('city_id')->all());

        $this->actingAsApi(User::factory()->client()->create())
            ->putJson(self::URL.'/coverage', ['coverage_city_ids' => [$this->jeddah->id]])
            ->assertForbidden();
    }
}
