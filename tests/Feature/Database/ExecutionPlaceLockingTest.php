<?php

namespace Tests\Feature\Database;

use App\Enums\ProviderStatus;
use App\Models\ProviderService;
use App\Services\Providers\ProviderSetup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * D74 with two real MySQL sessions on the `testing` database: while another session holds the service row mid-change,
 * a place change waits for it instead of acting on stale data, and after the other change commits it is checked
 * against that committed row. Needs committed rows, so this class does not use RefreshDatabase: it rebuilds the
 * schema before and after, leaving the empty, fully migrated database the other tests expect.
 */
class ExecutionPlaceLockingTest extends TestCase
{
    use BuildsApplications;

    private ProviderService $offered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertMatchesRegularExpression('/^testing(_[a-z0-9]+)?$/', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh');
        config(['database.connections.other_session' => config('database.connections.'.config('database.default'))]);
        DB::purge('other_session');

        $this->offered = $this->application(ProviderStatus::Active)->services()->sole();
        $this->offered->forceFill(['at_event_location' => true, 'event_location_confirmed_at' => now(), 'at_provider_location' => true])->save();
    }

    protected function tearDown(): void
    {
        DB::connection('other_session')->rollBack();
        DB::purge('other_session');
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
        parent::tearDown();
    }

    private function providerSetup(): ProviderSetup
    {
        return app(ProviderSetup::class);
    }

    public function test_a_place_change_waits_for_a_concurrent_one_and_then_sees_it(): void
    {
        $stale = ProviderService::query()->find($this->offered->id);

        // Session B: another request is switching the event location off and has not committed yet.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from provider_services where id = ? for update', [$this->offered->id]);
        $other->update('update provider_services set at_event_location = 0, event_location_confirmed_at = null where id = ?', [$this->offered->id]);

        // Session A (the app): it must block on the row lock, not decide on its stale copy.
        DB::statement('set session innodb_lock_wait_timeout = 1');
        try {
            $this->providerSetup()->updateService($stale, ['at_provider_location' => false]);
            $this->fail('The change did not wait for the locked row.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null, $e->getMessage()); // Lock wait timeout exceeded
        }

        // B commits; A tries again from the same stale copy and is refused against the committed row.
        $other->commit();
        try {
            $this->providerSetup()->updateService($stale, ['at_provider_location' => false]);
            $this->fail('The last place was switched off.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('at_provider_location', $e->errors());
        }

        $row = DB::table('provider_services')->where('id', $this->offered->id)->first();
        $this->assertSame(0, (int) $row->at_event_location);
        $this->assertSame(1, (int) $row->at_provider_location);
    }

    public function test_the_row_is_locked_while_the_rule_is_checked_and_released_after(): void
    {
        $other = DB::connection('other_session');
        $seen = [];
        DB::listen(function ($query) use ($other, &$seen) {
            if ($query->connectionName !== config('database.default') || ! str_contains($query->sql, 'for update') || ! str_contains($query->sql, 'provider_services')) {
                return;
            }
            try {
                $other->select('select id from provider_services where id = ? for update nowait', [$this->offered->id]);
                $seen[] = 'free';
            } catch (QueryException) {
                $seen[] = 'locked';
            }
        });

        $this->providerSetup()->setEventLocation(ProviderService::query()->find($this->offered->id), false);
        $this->providerSetup()->updateService(ProviderService::query()->find($this->offered->id), ['custom_price' => '900.00']);
        $this->providerSetup()->addService($this->offered->provider, $this->offered->service_id);

        $this->assertSame(['locked', 'locked', 'locked'], $seen);
        // Released once each change committed.
        $this->assertCount(1, $other->select('select id from provider_services where id = ? for update nowait', [$this->offered->id]));
    }
}
