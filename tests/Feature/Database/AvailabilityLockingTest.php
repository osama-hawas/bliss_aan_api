<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AvailabilityBlock;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Services\Availability\AvailabilityBlocks;
use App\Services\ServiceRequests\ServiceRequestCreator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAvailability;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * §10.1 with two real MySQL sessions: block creation, block deletion and direct-request creation of one provider are
 * serialized by the provider row lock (D90, D95). The other session plays the first transaction; this session must
 * wait for it (proved by a 1 s lock wait timeout), then decide on what it committed.
 */
class AvailabilityLockingTest extends TestCase
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

    private function other()
    {
        return DB::connection('other_session');
    }

    /**
     * The other session takes her provider lock, as the first of two racing transactions does.
     */
    private function otherLocksProvider(): void
    {
        $this->other()->beginTransaction();
        $this->other()->select('select id from providers where id = ? for update', [$this->noura->id]);
    }

    private function otherInsertsBlock(string $startsAt, string $endsAt): int
    {
        return $this->other()->table('availability_blocks')->insertGetId([
            'provider_id' => $this->noura->id, 'starts_at' => Carbon::parse($startsAt)->utc(), 'ends_at' => Carbon::parse($endsAt)->utc(),
            'created_by' => $this->noura->user_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function otherInsertsDirectRequest(string $startsAt, string $endsAt): int
    {
        $id = $this->other()->table('service_requests')->insertGetId([
            'reference' => 'RN-'.random_int(1000000, 9999999), 'client_id' => $this->client->id, 'type' => 'direct',
            'target_provider_id' => $this->noura->id, 'provider_service_id' => $this->noura->services()->sole()->id,
            'service_id' => $this->photo->id, 'event_type_id' => $this->wedding->id, 'execution_place' => 'event_location',
            'event_date' => Carbon::parse($startsAt)->setTimezone('Asia/Riyadh')->format('Y-m-d'), 'start_time' => '19:00:00', 'duration_minutes' => 60,
            'starts_at' => Carbon::parse($startsAt)->utc(), 'ends_at' => Carbon::parse($endsAt)->utc(), 'city_id' => $this->riyadh->id,
            'status' => 'awaiting_offers', 'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('0', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->other()->table('service_request_recipients')->insert([
            'service_request_id' => $id, 'provider_id' => $this->noura->id, 'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function createDirect(string $date = '2026-12-05', string $time = '19:00'): array
    {
        return app(ServiceRequestCreator::class)->create($this->client, (string) Str::uuid(), $this->directAt($this->noura, $date, $time));
    }

    private function blocks(): AvailabilityBlocks
    {
        return app(AvailabilityBlocks::class);
    }

    private function assertApiError(ErrorCode $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code->value}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_block_first_then_the_direct_request_is_refused(): void
    {
        $this->otherLocksProvider();
        $this->otherInsertsBlock('2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->createDirect());
        $this->other()->commit();

        $this->assertApiError(ErrorCode::SlotUnavailable, fn () => $this->createDirect());
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_request_first_then_the_block_is_created_and_lists_it(): void
    {
        $this->otherLocksProvider();
        $requestId = $this->otherInsertsDirectRequest('2026-12-05T16:00:00Z', '2026-12-05T17:00:00Z');

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->blocks()->create($this->noura->user, Carbon::parse('2026-12-04T21:00:00Z'), Carbon::parse('2026-12-05T21:00:00Z'), null));
        $this->other()->commit();

        [, $conflicting] = $this->blocks()->create($this->noura->user, Carbon::parse('2026-12-04T21:00:00Z'), Carbon::parse('2026-12-05T21:00:00Z'), null);
        $this->assertSame([$requestId], $conflicting->pluck('service_request_id')->all());
        $this->assertTrue($conflicting->first()->calendar_conflict);
    }

    public function test_deletion_first_then_the_direct_request_is_created(): void
    {
        $block = $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $this->otherLocksProvider();
        $this->other()->table('availability_blocks')->where('id', $block->id)->update(['deleted_at' => now(), 'deleted_by' => $this->noura->user_id]);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->createDirect());
        $this->other()->commit();

        [$request, $created] = $this->createDirect();
        $this->assertTrue($created);
        $this->assertSame(1, ServiceRequest::query()->count());
    }

    public function test_request_first_then_the_deletion_waits_and_succeeds(): void
    {
        $block = $this->storeBlock($this->noura, '2026-12-09T21:00:00Z', '2026-12-10T21:00:00Z');
        $this->otherLocksProvider();
        $this->otherInsertsDirectRequest('2026-12-05T16:00:00Z', '2026-12-05T17:00:00Z');

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->blocks()->delete($this->noura->user, $block->id));
        $this->assertNull($block->refresh()->deleted_at);
        $this->other()->commit();

        $this->blocks()->delete($this->noura->user, $block->id);
        $this->assertNotNull(AvailabilityBlock::withTrashed()->find($block->id)->deleted_at);
    }

    public function test_two_overlapping_block_creations_leave_one_row(): void
    {
        $this->otherLocksProvider();
        $this->otherInsertsBlock('2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');

        $this->shortLockWait();
        $create = fn () => $this->blocks()->create($this->noura->user, Carbon::parse('2026-12-05T10:00:00Z'), Carbon::parse('2026-12-05T12:00:00Z'), null);
        $this->assertWaits($create);
        $this->other()->commit();

        $this->assertApiError(ErrorCode::BlockOverlap, $create);
        $this->assertSame(1, AvailabilityBlock::query()->count());
    }

    public function test_deletion_first_then_an_overlapping_block_is_created(): void
    {
        $block = $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $this->otherLocksProvider();
        $this->other()->table('availability_blocks')->where('id', $block->id)->update(['deleted_at' => now(), 'deleted_by' => $this->noura->user_id]);

        $this->shortLockWait();
        $create = fn () => $this->blocks()->create($this->noura->user, Carbon::parse('2026-12-04T21:00:00Z'), Carbon::parse('2026-12-05T21:00:00Z'), null);
        $this->assertWaits($create);
        $this->other()->commit();

        [$new] = $create();
        $this->assertNotSame($block->id, $new->id);
        $this->assertSame(1, AvailabilityBlock::query()->count());
    }

    public function test_creation_first_then_the_deletion_of_the_old_block_still_succeeds(): void
    {
        $old = $this->storeBlock($this->noura, '2026-12-09T21:00:00Z', '2026-12-10T21:00:00Z');
        $this->otherLocksProvider();
        $this->otherInsertsBlock('2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->blocks()->delete($this->noura->user, $old->id));
        $this->other()->commit();

        $this->blocks()->delete($this->noura->user, $old->id);
        $this->assertSame(1, AvailabilityBlock::query()->count());
    }

    public function test_two_deletions_of_the_same_block_give_one_success_and_one_404(): void
    {
        $block = $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $this->otherLocksProvider();
        $this->other()->table('availability_blocks')->where('id', $block->id)->update(['deleted_at' => now(), 'deleted_by' => $this->noura->user_id]);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->blocks()->delete($this->noura->user, $block->id));
        $this->other()->commit();

        $this->assertApiError(ErrorCode::NotFound, fn () => $this->blocks()->delete($this->noura->user, $block->id));
    }
}
