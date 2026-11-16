<?php

namespace Tests\Feature\Database;

use App\Models\Media;
use App\Models\ServiceRequest;
use App\Services\ServiceRequests\ServiceRequestCreator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * §15 idempotency under real concurrency, on the MySQL `testing` database with committed rows:
 *  - two sessions: a create waits while another create of the same client holds her lock, then replays its result;
 *  - parallel processes: several separate PHP processes send the same Idempotency-Key at the same moment.
 * Needs committed rows, so this class does not use RefreshDatabase: it rebuilds the schema before and after, leaving
 * the empty, fully migrated database the other tests expect (same pattern as ExecutionPlaceLockingTest).
 */
class IdempotencyConcurrencyTest extends TestCase
{
    use BuildsServiceRequests;

    private const WORKERS = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertMatchesRegularExpression('/^testing(_[a-z0-9]+)?$/', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh');
        config(['database.connections.other_session' => config('database.connections.'.config('database.default'))]);
        DB::purge('other_session');

        $this->setUpMarket();
        $this->matchingProvider();
    }

    protected function tearDown(): void
    {
        if (DB::connection('other_session')->transactionLevel() > 0) {
            DB::connection('other_session')->rollBack();
        }
        DB::purge('other_session');
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
        parent::tearDown();
    }

    private function creator(): ServiceRequestCreator
    {
        return app(ServiceRequestCreator::class);
    }

    public function test_a_create_waits_for_a_parallel_create_of_the_same_client_and_then_replays_it(): void
    {
        $key = (string) Str::uuid();
        $body = $this->openBody();
        $fingerprint = $this->creator()->fingerprint($body);

        // Session B: the first call holds her lock and has written its row, not committed yet.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from users where id = ? for update', [$this->client->id]);
        $other->table('service_requests')->insert([
            'reference' => 'RN-0000001', 'client_id' => $this->client->id, 'type' => 'open', 'service_id' => $this->photo->id,
            'event_type_id' => $this->wedding->id, 'execution_place' => 'event_location', 'event_date' => $body['event_date'],
            'start_time' => '19:00:00', 'duration_minutes' => 180, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(3),
            'city_id' => $this->riyadh->id, 'status' => 'awaiting_offers', 'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $firstId = (int) $other->getPdo()->lastInsertId();

        // Session A (the app): it must wait for that lock, never create a second request.
        DB::statement('set session innodb_lock_wait_timeout = 1');
        try {
            $this->creator()->create($this->client, $key, $body);
            $this->fail('The second create did not wait for the first one.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null, $e->getMessage()); // Lock wait timeout exceeded
        }
        $this->assertSame(0, ServiceRequest::query()->count());

        // B commits; A's retry is a replay of B's request.
        $other->commit();
        [$request, $created] = $this->creator()->create($this->client, $key, $body);
        $this->assertFalse($created);
        $this->assertSame($firstId, $request->id);
        $this->assertSame(1, ServiceRequest::query()->count());
    }

    public function test_parallel_processes_with_the_same_key_create_one_request_and_replay_it(): void
    {
        $this->allowAttachments();
        $file = $this->attachmentUpload();
        $results = $this->race(array_fill(0, self::WORKERS, $this->openBody(['media_ids' => [$file->id]])), (string) Str::uuid());

        $this->assertSame(1, ServiceRequest::query()->count(), json_encode($results));
        $id = ServiceRequest::query()->sole()->id;
        foreach ($results as $result) {
            // No worker fails: not on the shared file, not on the unique index.
            $this->assertSame($id, $result['id'] ?? null, json_encode($results));
        }
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['created'])), json_encode($results));
        $this->assertSame($id, Media::query()->find($file->id)->attachable_id);
        $this->assertSame(1, ServiceRequest::query()->sole()->attachments()->count());
    }

    public function test_parallel_processes_with_the_same_key_and_different_bodies_create_one_request(): void
    {
        $bodies = [$this->openBody(), $this->openBody(['duration_minutes' => 60]), $this->openBody(['duration_minutes' => 90])];
        $results = $this->race($bodies, (string) Str::uuid());

        $this->assertSame(1, ServiceRequest::query()->count(), json_encode($results));
        $this->assertCount(1, array_filter($results, fn ($r) => ($r['created'] ?? false) === true), json_encode($results));
        $this->assertCount(2, array_filter($results, fn ($r) => ($r['error'] ?? null) === 'DUPLICATE_REQUEST'), json_encode($results));
    }

    /**
     * Starts one worker process per body, all calling create() at the same moment with the same key.
     *
     * @param  list<array<string, mixed>>  $bodies
     * @return list<array<string, mixed>>
     */
    private function race(array $bodies, string $key): array
    {
        $startAt = microtime(true) + 3.0;
        $env = $this->workerEnvironment();
        $processes = [];
        foreach ($bodies as $i => $body) {
            $file = tempnam(sys_get_temp_dir(), 'req');
            file_put_contents($file, json_encode($body));
            $process = new Process([PHP_BINARY, base_path('tests/Support/create_request_worker.php'), (string) $this->client->id, $key, $file, (string) $startAt], base_path(), $env, null, 60);
            $process->start();
            $processes[$i] = [$process, $file];
        }

        $results = [];
        foreach ($processes as [$process, $file]) {
            $process->wait();
            @unlink($file);
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    /**
     * The workers boot the app like this test run: same test database (`testing` or a worktree's `testing_<suffix>`) and settings, uncached config.
     *
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $env = [];
        $xml = simplexml_load_file(base_path('phpunit.xml'));
        foreach ($xml->php->env as $var) {
            $env[(string) $var['name']] = (string) $var['value'];
        }
        $connection = config('database.connections.'.config('database.default'));
        foreach (['database' => 'DB_DATABASE', 'host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $name) {
            $env[$name] = (string) ($connection[$key] ?? '');
        }

        return $env;
    }
}
