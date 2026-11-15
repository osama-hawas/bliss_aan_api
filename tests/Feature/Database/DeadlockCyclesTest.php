<?php

namespace Tests\Feature\Database;

use App\Models\Address;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequests\ServiceRequestCreator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsAvailability;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * §10.3 cycles reproduced for real: a separate MySQL session (tests/Support/sql_session_worker.php) plays the other
 * transaction while this session creates a request. InnoDB detects the cycle and aborts one side with 1213. The
 * worker first writes some rows so it is the heavier transaction and InnoDB picks this session as the victim, which
 * exercises the retry (D98, D100). Each test asserts a deadlock happened and the outcome is correct: one success
 * per side after the retry, never a duplicate row.
 */
class DeadlockCyclesTest extends TestCase
{
    use BuildsAvailability, UsesCommittedRows;

    /** Rows the worker writes first so InnoDB rolls back the lighter transaction (this session). */
    private const WEIGHT = 40;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    /**
     * Starts the worker and waits until it holds its first lock (it then sleeps `$hold` seconds).
     *
     * @param  list<array<int, mixed>>  $steps
     */
    private function startWorker(array $steps): Process
    {
        $file = tempnam(sys_get_temp_dir(), 'sql');
        file_put_contents($file, json_encode($steps));
        $connection = config('database.connections.'.config('database.default'));
        $process = new Process([PHP_BINARY, base_path('tests/Support/sql_session_worker.php'), $file], base_path(), [
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        ], null, 60);
        $process->start();
        usleep(700_000); // the worker has written its weight and taken its lock

        return $process;
    }

    /**
     * @return array<string, mixed>
     */
    private function finish(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function weight(): array
    {
        $steps = [['sql', 'start transaction']];
        for ($i = 0; $i < self::WEIGHT; $i++) {
            $steps[] = ['sql', "insert into activity_events (subject_type, subject_id, event, visibility, created_at, updated_at) values ('test', 0, 'weight', 'internal', now(), now())"];
        }

        return $steps;
    }

    /**
     * A committed-at-the-end service_requests row of another client (the other creation's insert).
     */
    private function requestInsert(int $clientId, ?int $targetProviderId): array
    {
        return ['sql', 'insert into service_requests (reference, client_id, type, target_provider_id, service_id, event_type_id, execution_place, event_date, start_time, duration_minutes, starts_at, ends_at, city_id, status, idempotency_key, idempotency_fingerprint, created_at, updated_at)
            values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())', [
            'RN-'.random_int(1000000, 9999999), $clientId, $targetProviderId ? 'direct' : 'open', $targetProviderId, $this->photo->id,
            $this->wedding->id, 'event_location', '2026-12-09', '19:00:00', 60, '2026-12-09 16:00:00', '2026-12-09 17:00:00',
            $this->riyadh->id, 'awaiting_offers', (string) Str::uuid(), str_repeat('0', 64),
        ]];
    }

    private function create(array $body): array
    {
        return app(ServiceRequestCreator::class)->create($this->client, (string) Str::uuid(), $body);
    }

    private function assertRetriedDeadlock(): void
    {
        Log::shouldHaveReceived('warning')->with('Deadlock, retrying.', Mockery::on(fn ($c) => $c['code'] === 1213))->atLeast()->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_c1_two_clients_direct_requests_to_the_same_provider_in_one_index_gap(): void
    {
        Log::spy();
        $other = User::factory()->client()->create();
        // The other client's direct creation: holds her provider row (step 4), then inserts into the empty
        // (client_id, idempotency_key) index, whose whole range this session's key check has gap-locked.
        $worker = $this->startWorker([
            ...$this->weight(),
            ['sql', 'select id from providers where id = ? for update', [$this->noura->id]],
            ['sleep', 1.0],
            $this->requestInsert($other->id, $this->noura->id),
            ['sql', 'commit'],
        ]);

        [$request, $created] = $this->create($this->directAt($this->noura, '2026-12-05', '19:00'));
        $result = $this->finish($worker);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertTrue($created);
        $this->assertRetriedDeadlock();
        $this->assertSame(2, ServiceRequest::query()->count());
        $this->assertSame(1, ServiceRequest::query()->where('client_id', $this->client->id)->count());
    }

    public function test_c1_direct_request_against_an_open_creation_holding_the_provider_in_share_mode(): void
    {
        Log::spy();
        $other = User::factory()->client()->create();
        $worker = $this->startWorker([
            ...$this->weight(),
            ['sql', 'select id from providers where id = ? lock in share mode', [$this->noura->id]], // recipients' FK check
            ['sleep', 1.0],
            $this->requestInsert($other->id, null),
            ['sql', 'commit'],
        ]);

        [, $created] = $this->create($this->directAt($this->noura, '2026-12-05', '19:00'));
        $result = $this->finish($worker);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertTrue($created);
        $this->assertRetriedDeadlock();
        $this->assertSame(1, ServiceRequest::query()->where('client_id', $this->client->id)->count());
    }

    public function test_c1_open_request_as_the_aborted_side_is_retried_with_its_recipients(): void
    {
        Log::spy();
        $huda = $this->matchingProvider('Huda Studio');
        $other = User::factory()->client()->create();
        // The other creation's key check gap-locks the index, then it inserts; this open creation inserts in between.
        $worker = $this->startWorker([
            ...$this->weight(),
            ['sql', 'select id from service_requests where client_id = ? and idempotency_key = ? for update', [$other->id, (string) Str::uuid()]],
            ['sleep', 1.0],
            $this->requestInsert($other->id, $this->noura->id),
            ['sql', 'commit'],
        ]);

        [$request, $created] = $this->create($this->openBody(['event_date' => '2026-12-05']));
        $result = $this->finish($worker);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertTrue($created);
        $this->assertRetriedDeadlock();
        $this->assertSame(1, ServiceRequest::query()->where('client_id', $this->client->id)->count());
        $this->assertEqualsCanonicalizing([$this->noura->id, $huda->id], $request->recipients()->pluck('provider_id')->all());
    }

    public function test_c2_address_creation_against_request_creation_of_the_same_client(): void
    {
        Log::spy();
        $address = Address::query()->create([
            'user_id' => $this->client->id, 'city_id' => $this->riyadh->id, 'details' => 'Villa 1', 'lat' => 24.7, 'lng' => 46.6, 'is_default' => true,
        ]);
        // AddressBook::create: all her addresses X, then the insert's FK check needs S on her users row, which this
        // session's request creation holds X while it needs S on the saved address.
        $worker = $this->startWorker([
            ...$this->weight(),
            ['sql', 'select id from addresses where user_id = ? for update', [$this->client->id]],
            ['sleep', 1.0],
            ['sql', "insert into addresses (user_id, city_id, details, is_default, created_at, updated_at) values (?, ?, 'Villa 2', 0, now(), now())", [$this->client->id, $this->riyadh->id]],
            ['sql', 'commit'],
        ]);

        $body = $this->directAt($this->noura, '2026-12-05', '19:00');
        unset($body['address']);
        [$request, $created] = $this->create([...$body, 'address_id' => $address->id]);
        $result = $this->finish($worker);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertTrue($created);
        $this->assertRetriedDeadlock();
        $this->assertSame(1, ServiceRequest::query()->count());
        $this->assertSame(2, Address::query()->count());
        $this->assertSame($address->id, $request->address_id);
    }

    public function test_add_service_gap_lock_does_not_block_a_direct_creation_referencing_another_of_her_services(): void
    {
        $second = $this->service($this->category('Second'));
        // ProviderSetup::addService for a new service: locking read on (provider, service) finds nothing → gap lock,
        // then inserts (S on providers). This session's creation references her existing photo service row.
        $worker = $this->startWorker([
            ['sql', 'start transaction'],
            ['sql', 'select id from provider_services where provider_id = ? and service_id = ? for update', [$this->noura->id, $second->id]],
            ['sleep', 1.0],
            ['sql', 'insert into provider_services (provider_id, service_id, is_active, at_event_location, at_provider_location, created_at, updated_at) values (?, ?, 1, 0, 1, now(), now())', [$this->noura->id, $second->id]],
            ['sql', 'commit'],
        ]);

        $this->shortLockWait(); // a wait on the worker would fail with 1205 instead of passing
        [, $created] = $this->create($this->directAt($this->noura, '2026-12-05', '19:00'));
        $result = $this->finish($worker);

        $this->assertTrue($created);
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(2, DB::table('provider_services')->where('provider_id', $this->noura->id)->count());
    }
}
