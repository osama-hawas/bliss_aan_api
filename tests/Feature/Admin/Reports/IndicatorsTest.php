<?php

namespace Tests\Feature\Admin\Reports;

use App\Enums\AdminRole;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 21 (S21): KPI groups read from the source records, scoped by period, city and role (D316–D319).
 */
class IndicatorsTest extends TestCase
{
    use BuildsApplications, BuildsBookings, RefreshDatabase;

    private const URL = '/api/v1/admin/indicators';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    private function group(array $groups, string $key): ?array
    {
        return collect($groups)->firstWhere('key', $key);
    }

    private function item(array $group, string $key): array
    {
        return collect($group['items'])->firstWhere('key', $key);
    }

    public function test_counts_and_booked_totals_per_status_with_drill_down_links(): void
    {
        $this->booking($this->noura);
        $this->booking($this->noura, User::factory()->client()->create());
        $admin = $this->staff(AdminRole::OperationsSupport);

        $groups = $this->actingAsApi($admin)->getJson(self::URL)->assertOk()->json('data.groups');

        $bookings = $this->group($groups, 'bookings');
        $this->assertSame(2, $this->item($bookings, 'confirmed')['value']);
        $this->assertSame(['amount' => '2000.00', 'currency' => 'SAR'], $this->item($bookings, 'confirmed')['amount']);
        $this->assertSame('/bookings?status=confirmed', $this->item($bookings, 'confirmed')['href']);
        $this->assertSame(2, $this->item($this->group($groups, 'requests'), 'confirmed')['value']);
        $this->assertSame(0, $this->item($bookings, 'cancelled')['value']);
    }

    public function test_period_and_city_limit_the_counts_and_sources_without_a_city_say_so(): void
    {
        $this->booking($this->noura);
        $admin = $this->staff(AdminRole::OperationsSupport);
        $other = $this->city();

        $groups = $this->actingAsApi($admin)->getJson(self::URL.'?filter[from]=2026-12-02')->assertOk()->json('data.groups');
        $this->assertSame(0, $this->item($this->group($groups, 'bookings'), 'confirmed')['value']);

        $groups = $this->actingAsApi($admin)->getJson(self::URL.'?filter[from]=2026-12-01&filter[to]=2026-12-01&filter[city_id]='.$this->riyadh->id)
            ->assertOk()->assertJsonPath('data.scope.city_id', $this->riyadh->id)->json('data.groups');
        $this->assertSame(1, $this->item($this->group($groups, 'bookings'), 'confirmed')['value']);
        $this->assertStringContainsString('city_id='.$this->riyadh->id, $this->item($this->group($groups, 'bookings'), 'confirmed')['href']);
        // Cancellations and tickets have no city: null, never a false 0.
        $this->assertNull($this->item($this->group($groups, 'cancellations'), 'under_review')['value']);
        $this->assertNull($this->item($this->group($groups, 'support'), 'open')['value']);

        $groups = $this->actingAsApi($admin)->getJson(self::URL.'?filter[city_id]='.$other->id)->assertOk()->json('data.groups');
        $this->assertSame(0, $this->item($this->group($groups, 'bookings'), 'confirmed')['value']);

        $this->actingAsApi($admin)->getJson(self::URL.'?filter[from]=2026-12-05&filter[to]=2026-12-01')->assertUnprocessable();
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[from]=01-12-2026')->assertUnprocessable();
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[city_id]=abc')->assertUnprocessable();
    }

    public function test_groups_follow_the_role(): void
    {
        $officer = $this->staff(AdminRole::AccountsOfficer);
        $keys = collect($this->actingAsApi($officer)->getJson(self::URL)->assertOk()->json('data.groups'))->pluck('key')->all();

        $this->assertSame(['clients', 'providers', 'applications'], $keys);

        $store = $this->staff(AdminRole::StoreContentOfficer);
        $this->assertNotContains('bookings', collect($this->actingAsApi($store)->getJson(self::URL)->json('data.groups'))->pluck('key')->all());

        $this->actingAsApi($this->staff(AdminRole::DeliveryOfficer))->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($this->client)->getJson(self::URL)->assertForbidden();
    }

    public function test_drill_down_lists_the_records_behind_an_item(): void
    {
        $booking = $this->booking($this->noura);
        $admin = $this->staff(AdminRole::OperationsSupport);

        $this->actingAsApi($admin)->getJson(self::URL.'/bookings/confirmed/records')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', $booking->reference)
            ->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('data.0.amount.amount', '1000.00')
            ->assertJsonPath('data.0.href', "/bookings/{$booking->id}")
            ->assertJsonStructure(['data' => [['id', 'reference', 'status', 'status_label', 'date', 'city', 'party', 'amount', 'href']]]);

        $this->actingAsApi($admin)->getJson(self::URL.'/bookings/cancelled/records')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsApi($admin)->getJson(self::URL.'/bookings/nope/records')->assertNotFound();
        $this->actingAsApi($admin)->getJson(self::URL.'/unknown/confirmed/records')->assertNotFound();
        // A group without a city cannot be drilled by city.
        $this->actingAsApi($admin)->getJson(self::URL.'/cancellations/under_review/records?filter[city_id]='.$this->riyadh->id)->assertNotFound();
        // A group outside her role is a 404, not a 403 (no leak).
        $this->actingAsApi($this->staff(AdminRole::StoreContentOfficer))->getJson(self::URL.'/bookings/confirmed/records')->assertNotFound();
    }

    public function test_groups_of_slices_not_merged_yet_are_absent_until_their_tables_exist(): void
    {
        $groups = collect($this->actingAsApi($this->staff(AdminRole::SuperAdmin))->getJson(self::URL)->assertOk()->json('data.groups'))->pluck('key');

        foreach (['payments' => 'payments', 'refunds' => 'refunds', 'revenue' => 'payments', 'orders' => 'store_orders', 'payouts' => 'payout_requests'] as $group => $table) {
            $this->assertSame(Schema::hasTable($table), $groups->contains($group), $group);
        }
    }
}
