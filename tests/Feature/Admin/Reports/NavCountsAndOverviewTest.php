<?php

namespace Tests\Feature\Admin\Reports;

use App\Enums\AdminRole;
use App\Enums\ProviderStatus;
use App\Models\AppSetting;
use App\Models\Provider;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 21 (S21): live sidebar badges (D327) and the dashboard home (D328), both scoped by role (D317).
 */
class NavCountsAndOverviewTest extends TestCase
{
    use BuildsApplications, BuildsBookings, RefreshDatabase;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    public function test_nav_counts_show_only_the_queues_of_her_role(): void
    {
        $this->application(ProviderStatus::Submitted);
        $this->application(ProviderStatus::Submitted);
        $this->application(ProviderStatus::NeedsCompletion);
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();

        $this->assertSame(['applications' => 2], $this->actingAsApi($this->staff(AdminRole::AccountsOfficer))->getJson('/api/v1/admin/nav-counts')->assertOk()->json('data'));

        $ops = $this->actingAsApi($this->staff(AdminRole::OperationsSupport))->getJson('/api/v1/admin/nav-counts')->assertOk()->json('data');
        $this->assertSame(['cancellations' => 1, 'tickets' => 0, 'reports' => 0], $ops);

        $all = $this->actingAsApi($this->staff(AdminRole::SuperAdmin))->getJson('/api/v1/admin/nav-counts')->assertOk()->json('data');
        $this->assertSame(['applications', 'cancellations', 'tickets', 'reports', 'orders', 'payouts', 'deliveries'], array_keys($all));

        // The delivery officer has no dashboard.view but still gets her own badge (values: MoneyKpisTest).
        $this->assertSame(['deliveries' => 0], $this->actingAsApi($this->staff(AdminRole::DeliveryOfficer))->getJson('/api/v1/admin/nav-counts')->assertOk()->json('data'));
        $this->actingAsApi($this->client)->getJson('/api/v1/admin/nav-counts')->assertForbidden();
    }

    public function test_overview_queue_figures_trends_and_upcoming_bookings(): void
    {
        $this->application(ProviderStatus::Submitted);
        $booking = $this->booking($this->noura);

        $data = $this->actingAsApi($this->staff(AdminRole::SuperAdmin))->getJson('/api/v1/admin/overview')->assertOk()->json('data');

        $queue = collect($data['queue'])->keyBy('key');
        $this->assertSame(1, $queue['applications']['count']);
        $this->assertSame('/provider-applications?status=submitted', $queue['applications']['href']);
        $this->assertArrayNotHasKey('lowStock', $queue->all()); // D320: no threshold yet
        $this->assertSame(1, collect($data['figures'])->firstWhere('key', 'bookings')['value']);
        $requests = collect($data['trends'])->firstWhere('key', 'requests');
        $this->assertCount(8, $requests['points']);
        $this->assertSame(1, array_sum(array_column($requests['points'], 'count')));
        $this->assertSame($booking->reference, $data['upcoming'][0]['reference']);
        $this->assertSame('Noura Lens', $data['upcoming'][0]['provider']['name']);
    }

    public function test_low_stock_appears_once_a_threshold_is_set(): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'store.low_stock_threshold'], ['value' => 5]);
        app(AppSettings::class)->flush();

        $queue = collect($this->actingAsApi($this->staff(AdminRole::StoreContentOfficer))->getJson('/api/v1/admin/overview')->assertOk()->json('data.queue'))->keyBy('key');

        $this->assertArrayHasKey('lowStock', $queue->all());
        $this->assertArrayNotHasKey('applications', $queue->all());
    }

    public function test_overview_follows_the_role(): void
    {
        $data = $this->actingAsApi($this->staff(AdminRole::AccountsOfficer))->getJson('/api/v1/admin/overview')->assertOk()->json('data');

        $this->assertSame([], $data['upcoming']);
        $this->assertSame([], $data['trends']);
        $this->assertEqualsCanonicalizing(['clients', 'providers'], array_column($data['figures'], 'key'));

        $this->actingAsApi($this->staff(AdminRole::DeliveryOfficer))->getJson('/api/v1/admin/overview')->assertForbidden();
    }
}
