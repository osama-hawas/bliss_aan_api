<?php

namespace Tests\Feature\Database;

use App\Models\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * D73 migration on rows that existed before it: their `at_event_location` is kept and none is confirmed; the
 * default becomes false; rollback and re-application keep the data. Runs on the `testing` database only.
 * Schema changes commit in MySQL, so this class does not use RefreshDatabase: it rebuilds the schema itself
 * before and after, leaving the empty, fully migrated database the other tests expect.
 */
class EventLocationMigrationTest extends TestCase
{
    use BuildsCatalog;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh');
        $this->migration = require database_path('migrations/2026_11_09_100000_add_event_location_confirmation_to_provider_services.php');
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
        parent::tearDown();
    }

    private function eventLocationDefault(): string
    {
        return (string) DB::selectOne(
            'select column_default as d from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            ['provider_services', 'at_event_location'],
        )->d;
    }

    public function test_existing_rows_keep_their_value_and_stay_unconfirmed_through_up_down_up(): void
    {
        // The schema as it was before D73.
        $this->migration->down();
        $this->assertFalse(Schema::hasColumn('provider_services', 'event_location_confirmed_at'));
        $this->assertSame('1', $this->eventLocationDefault());

        $category = $this->category();
        $provider = $this->provider();
        $legacy = DB::table('provider_services')->insertGetId(['provider_id' => $provider->id, 'service_id' => $this->service($category)->id, 'created_at' => now(), 'updated_at' => now()]);
        $off = DB::table('provider_services')->insertGetId(['provider_id' => $provider->id, 'service_id' => $this->service($category, 'Video')->id, 'at_event_location' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, (int) DB::table('provider_services')->where('id', $legacy)->value('at_event_location'));

        $this->migration->up();

        $this->assertSame('0', $this->eventLocationDefault());
        $this->assertTrue(Schema::hasColumn('provider_services', 'event_location_confirmed_at'));
        $this->assertSame(1, (int) DB::table('provider_services')->where('id', $legacy)->value('at_event_location'));
        $this->assertSame(0, (int) DB::table('provider_services')->where('id', $off)->value('at_event_location'));
        $this->assertSame(0, DB::table('provider_services')->whereNotNull('event_location_confirmed_at')->count());

        // A row added afterwards without a value starts off.
        $fresh = DB::table('provider_services')->insertGetId(['provider_id' => $this->provider('Huda')->id, 'service_id' => $this->service($category, 'Album')->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, (int) DB::table('provider_services')->where('id', $fresh)->value('at_event_location'));

        // Rollback keeps the values; re-applying confirms nothing.
        DB::table('provider_services')->where('id', $legacy)->update(['event_location_confirmed_at' => now()]);
        $this->migration->down();
        $this->assertFalse(Schema::hasColumn('provider_services', 'event_location_confirmed_at'));
        $this->assertSame(1, (int) DB::table('provider_services')->where('id', $legacy)->value('at_event_location'));
        $this->assertSame(3, DB::table('provider_services')->count());

        $this->migration->up();
        $this->assertSame('0', $this->eventLocationDefault());
        $this->assertSame(1, (int) DB::table('provider_services')->where('id', $legacy)->value('at_event_location'));
        $this->assertSame(0, DB::table('provider_services')->whereNotNull('event_location_confirmed_at')->count());
        $this->assertInstanceOf(Provider::class, $provider->refresh());
    }
}
