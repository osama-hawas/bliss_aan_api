<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\ActivityEvent;
use App\Models\AvailabilityBlock;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\AvailabilityBlocks;
use App\Services\Availability\DeadlockRetry;
use App\Services\ServiceRequests\ReferenceGenerator;
use App\Services\ServiceRequests\ServiceRequestCreator;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDOException;
use Tests\Concerns\BuildsAvailability;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * D98 / D100 / §10.3: a deadlock (MySQL 1213) inside the 5a transactions is retried in a fresh transaction, up to 3
 * attempts, re-running the complete sequence; nothing else is retried. Deadlocks are forced from inside the
 * transaction (after the locks were taken) by throwing the driver's 1213 from a collaborator; real cycles are in
 * DeadlockCyclesTest. Committed rows, so each attempt is a real outermost transaction.
 */
class DeadlockRetryTest extends TestCase
{
    use BuildsAvailability, UsesCommittedRows;

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

    public static function sqlError(int $code): QueryException
    {
        $pdo = new PDOException("SQLSTATE: driver error {$code}");
        $pdo->errorInfo = [$code === 1213 ? '40001' : 'HY000', $code, "driver error {$code}"];

        return new QueryException('mysql', 'insert into service_requests …', [], $pdo);
    }

    /**
     * References that run `$before` and then fail with `$code` on the first `$failures` calls.
     */
    private function failingReferences(int $failures, int $code = 1213, ?Closure $before = null): object
    {
        $fake = new class($failures, $code, $before) extends ReferenceGenerator
        {
            public int $calls = 0;

            public function __construct(private int $failures, private int $code, private ?Closure $before) {}

            public function next(): string
            {
                $this->calls++;
                if ($this->calls <= $this->failures) {
                    if ($this->before) {
                        ($this->before)($this->calls);
                    }
                    throw DeadlockRetryTest::sqlError($this->code);
                }

                return 'RN-'.str_pad((string) $this->calls, 7, '0', STR_PAD_LEFT);
            }
        };
        $this->app->instance(ReferenceGenerator::class, $fake);

        return $fake;
    }

    private function create(array $body, ?string $key = null): array
    {
        return app(ServiceRequestCreator::class)->create($this->client, $key ?? (string) Str::uuid(), $body);
    }

    public function test_a_deadlock_on_the_first_attempt_is_retried_and_creates_exactly_one_request(): void
    {
        Log::spy();
        $this->allowAttachments();
        $file = $this->attachmentUpload();
        $fake = $this->failingReferences(1);

        [$request, $created] = $this->create($this->directAt($this->noura, '2026-12-05', '19:00', 60, ['media_ids' => [$file->id]]));

        $this->assertTrue($created);
        $this->assertSame(2, $fake->calls);
        $this->assertSame(1, ServiceRequest::query()->count());
        $this->assertSame(1, ServiceRequestRecipient::query()->count());
        $this->assertSame(1, ActivityEvent::query()->where('event', 'request_created')->count());
        $this->assertSame($request->id, Media::query()->find($file->id)->attachable_id);
        Log::shouldHaveReceived('warning')->once()->with('Deadlock, retrying.', Mockery::on(fn ($c) => $c['operation'] === 'service_request.create' && $c['attempt'] === 1));
        Log::shouldNotHaveReceived('error');
    }

    public function test_three_deadlocks_answer_500_leave_nothing_and_log_once(): void
    {
        Log::spy();
        $this->allowAttachments();
        $file = $this->attachmentUpload();
        $fake = $this->failingReferences(3);
        $key = (string) Str::uuid();

        $this->submit($this->directAt($this->noura, '2026-12-05', '19:00', 60, ['media_ids' => [$file->id]]), $key)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Something went wrong. Please try again.', 'code' => 'SERVER_ERROR', 'errors' => []]);

        $this->assertSame(3, $fake->calls);
        $this->assertSame(0, ServiceRequest::query()->count());
        $this->assertSame(0, ServiceRequestRecipient::query()->count());
        $this->assertSame(0, ActivityEvent::query()->count());
        $this->assertNull(Media::query()->find($file->id)->attachable_id);
        Log::shouldHaveReceived('error')->once()->with('Deadlock retries exhausted.', Mockery::on(fn ($c) => $c['operation'] === 'service_request.create'
            && $c['attempts'] === 3 && $c['code'] === 1213 && $c['user_id'] === $this->client->id
            && $c['provider_id'] === $this->noura->id && $c['idempotency_key'] === $key && ! array_key_exists('address', $c)));

        // The same key may be retried later (nothing holds it).
        $this->app->instance(ReferenceGenerator::class, new ReferenceGenerator);
        $this->submit($this->directAt($this->noura, '2026-12-05', '19:00', 60, ['media_ids' => [$file->id]]), $key)->assertCreated();
    }

    public function test_lock_wait_timeouts_and_other_sql_errors_are_not_retried(): void
    {
        foreach ([1205, 1452] as $code) {
            $fake = $this->failingReferences(1, $code);
            try {
                $this->create($this->directAt($this->noura, '2026-12-05', '19:00'));
                $this->fail('Expected the SQL error.');
            } catch (QueryException $e) {
                $this->assertSame($code, $e->errorInfo[1]);
            }
            $this->assertSame(1, $fake->calls, "error {$code} was retried");
        }
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_api_and_validation_errors_are_not_retried(): void
    {
        $attempts = 0;
        $retry = app(DeadlockRetry::class);
        foreach ([new ApiException(ErrorCode::SlotUnavailable), ValidationException::withMessages(['x' => 'y'])] as $error) {
            $attempts = 0;
            try {
                $retry->run(function () use (&$attempts, $error) {
                    $attempts++;
                    throw $error;
                }, 'test');
            } catch (ApiException|ValidationException) {
            }
            $this->assertSame(1, $attempts);
        }
    }

    public function test_inside_an_open_transaction_the_work_runs_once(): void
    {
        $attempts = 0;
        DB::beginTransaction();
        try {
            app(DeadlockRetry::class)->run(function () use (&$attempts) {
                $attempts++;
                throw self::sqlError(1213);
            }, 'test');
        } catch (QueryException) {
        } finally {
            DB::rollBack();
        }
        $this->assertSame(1, $attempts);
    }

    /**
     * Runs `$between` from another session after the first attempt was rolled back, before the second one.
     */
    private function betweenAttempts(Closure $between): void
    {
        $this->app->instance(DeadlockRetry::class, new class($between) extends DeadlockRetry
        {
            public function __construct(private Closure $between) {}

            protected function beforeRetry(int $nextAttempt): void
            {
                ($this->between)(DB::connection('other_session'));
            }
        });
        $this->failingReferences(1);
    }

    public function test_a_replay_committed_between_attempts_is_answered_as_a_replay(): void
    {
        $key = (string) Str::uuid();
        $body = $this->directAt($this->noura, '2026-12-05', '19:00');
        $fingerprint = app(ServiceRequestCreator::class)->fingerprint($body);
        $twinId = null;

        $this->betweenAttempts(function ($other) use ($key, $fingerprint, $body, &$twinId) {
            $twinId = $other->table('service_requests')->insertGetId([
                'reference' => 'RN-7777777', 'client_id' => $this->client->id, 'type' => 'direct', 'target_provider_id' => $this->noura->id,
                'provider_service_id' => $this->noura->services()->sole()->id, 'service_id' => $this->photo->id,
                'event_type_id' => $this->wedding->id, 'execution_place' => 'event_location', 'event_date' => $body['event_date'],
                'start_time' => '19:00:00', 'duration_minutes' => 60, 'starts_at' => '2026-12-05 16:00:00', 'ends_at' => '2026-12-05 17:00:00',
                'city_id' => $this->riyadh->id, 'status' => 'awaiting_offers', 'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        [$request, $created] = $this->create($body, $key);

        $this->assertFalse($created);
        $this->assertSame($twinId, $request->id);
        $this->assertSame(1, ServiceRequest::query()->count());
    }

    public function test_a_block_added_between_attempts_is_applied_by_the_later_attempt(): void
    {
        $this->betweenAttempts(fn ($other) => $other->table('availability_blocks')->insert([
            'provider_id' => $this->noura->id, 'starts_at' => '2026-12-04 21:00:00', 'ends_at' => '2026-12-05 21:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        try {
            $this->create($this->directAt($this->noura, '2026-12-05', '19:00'));
            $this->fail('Expected SLOT_UNAVAILABLE.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::SlotUnavailable, $e->errorCode);
        }
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_a_provider_hidden_between_attempts_is_refused_by_the_later_attempt(): void
    {
        $this->betweenAttempts(fn ($other) => $other->table('providers')->where('id', $this->noura->id)->update(['status' => 'suspended']));

        try {
            $this->create($this->directAt($this->noura, '2026-12-05', '19:00'));
            $this->fail('Expected the visibility error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('provider_id', $e->errors());
        }
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_an_open_request_aborted_by_a_deadlock_is_retried_with_its_recipients(): void
    {
        $huda = $this->matchingProvider('Huda Studio');
        $fake = $this->failingReferences(1);

        [$request, $created] = $this->create($this->openBody(['event_date' => '2026-12-05']));

        $this->assertTrue($created);
        $this->assertSame(2, $fake->calls);
        $this->assertSame(1, ServiceRequest::query()->count());
        $this->assertEqualsCanonicalizing([$this->noura->id, $huda->id], $request->recipients()->pluck('provider_id')->all());
    }

    public function test_block_creation_and_deletion_are_retried_on_a_deadlock(): void
    {
        Log::spy();
        $calls = 0;
        $recorder = new class($calls) extends ActivityRecorder
        {
            public function __construct(public int &$calls) {}

            public function record(...$args): ActivityEvent
            {
                if (++$this->calls === 1) {
                    throw DeadlockRetryTest::sqlError(1213);
                }

                return parent::record(...$args);
            }
        };
        $this->app->instance(ActivityRecorder::class, $recorder);
        $blocks = app(AvailabilityBlocks::class);

        [$block] = $blocks->create($this->noura->user, Carbon::parse('2026-12-04T21:00:00Z'), Carbon::parse('2026-12-05T21:00:00Z'), null);
        $this->assertSame(2, $calls);
        $this->assertSame(1, AvailabilityBlock::withTrashed()->count());
        $this->assertSame(1, ActivityEvent::query()->where('event', 'availability_blocked')->count());

        $calls = 0;
        $blocks->delete($this->noura->user, $block->id);
        $this->assertSame(2, $calls);
        $this->assertNotNull(AvailabilityBlock::withTrashed()->sole()->deleted_at);
        $this->assertSame(1, ActivityEvent::query()->where('event', 'availability_unblocked')->count());
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_block_creation_with_three_deadlocks_answers_500_and_stores_nothing(): void
    {
        Log::spy();
        $this->app->instance(ActivityRecorder::class, new class extends ActivityRecorder
        {
            public function record(...$args): ActivityEvent
            {
                throw DeadlockRetryTest::sqlError(1213);
            }
        });

        $this->block($this->noura, ['date' => '2026-12-05'])->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');
        $this->assertSame(0, AvailabilityBlock::withTrashed()->count());
        Log::shouldHaveReceived('error')->once()->with('Deadlock retries exhausted.', Mockery::on(fn ($c) => $c['operation'] === 'availability_block.create'
            && $c['attempts'] === 3 && $c['code'] === 1213 && $c['provider_id'] === $this->noura->id && $c['starts_at'] === '2026-12-04T21:00:00Z'));
    }
}
