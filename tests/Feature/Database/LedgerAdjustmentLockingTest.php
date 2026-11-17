<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Enums\LedgerAdjustmentStatus;
use App\Exceptions\ApiException;
use App\Models\LedgerAdjustment;
use App\Services\Finance\LedgerAdjustments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsFinance;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 19 §4 with two real MySQL sessions (run after the S11 merge): an approval holds the adjustment row, a second
 * approver waits and then sees it posted (one ledger row); a create waits for the provider row; same-key creates replay.
 */
class LedgerAdjustmentLockingTest extends TestCase
{
    use BuildsCatalog, BuildsFinance, UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    public function test_a_second_approver_waits_and_finds_it_posted(): void
    {
        $this->setLimits('1000.00', null);
        $provider = $this->provider();
        $alice = $this->financeStaff();
        $bea = $this->financeStaff();
        $cara = $this->financeStaff();
        [$adjustment] = app(LedgerAdjustments::class)->create($alice, (string) Str::uuid(), [
            'provider_id' => $provider->id, 'direction' => 'credit', 'amount' => '100.00', 'reason_code' => 'goodwill', 'reason' => 'Race test',
        ]);

        // The other session is Bea's approval in flight: it holds the adjustment row.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from ledger_adjustments where id = ? for update', [$adjustment->id]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(LedgerAdjustments::class)->approve($cara, $adjustment->id));
        $other->rollBack();

        app(LedgerAdjustments::class)->approve($bea, $adjustment->id);
        $this->assertSame(LedgerAdjustmentStatus::Posted, app(LedgerAdjustments::class)->approve($cara, $adjustment->id)->status);
        $this->assertCount(1, $this->adjustmentEntries($provider));
    }

    public function test_create_waits_for_the_provider_row(): void
    {
        $this->setLimits('1000.00', '500.00');
        $provider = $this->provider();
        $alice = $this->financeStaff();

        // The other session is a payout request (S11) holding her providers row.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from providers where id = ? for update', [$provider->id]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(LedgerAdjustments::class)->create($alice, (string) Str::uuid(), [
            'provider_id' => $provider->id, 'direction' => 'debit', 'amount' => '10.00', 'reason_code' => 'penalty', 'reason' => 'Race test',
        ]));
        $other->rollBack();
        $this->assertSame(0, LedgerAdjustment::query()->count());
    }

    public function test_same_key_after_the_first_commit_replays(): void
    {
        $this->setLimits('1000.00', '500.00');
        $provider = $this->provider();
        $alice = $this->financeStaff();
        $key = (string) Str::uuid();
        $body = ['provider_id' => $provider->id, 'direction' => 'credit', 'amount' => '10.00', 'reason_code' => 'goodwill', 'reason' => 'Replay'];

        [$first, $created] = app(LedgerAdjustments::class)->create($alice, $key, $body);
        [$again, $createdAgain] = app(LedgerAdjustments::class)->create($alice, $key, $body);

        $this->assertTrue($created);
        $this->assertFalse($createdAgain);
        $this->assertSame($first->id, $again->id);
        $this->assertCount(1, $this->adjustmentEntries($provider));

        try {
            app(LedgerAdjustments::class)->create($alice, $key, [...$body, 'amount' => '11.00']);
            $this->fail('A different body with the same key was accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::DuplicateRequest, $e->errorCode);
        }
    }
}
