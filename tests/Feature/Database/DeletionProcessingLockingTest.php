<?php

namespace Tests\Feature\Database;

use App\Enums\DeletionReason;
use App\Enums\DeletionRequestStatus;
use App\Enums\UserStatus;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Services\Account\AccountDeletion;
use App\Services\Account\AccountDeletionProcessor;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * S22 with two real MySQL sessions: processing takes her `users` row first (D333), so anything that starts an obligation
 * under that lock, or a withdraw of the request, is serialized with the anonymisation.
 */
class DeletionProcessingLockingTest extends TestCase
{
    use UsesCommittedRows;

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

    public function test_an_obligation_committed_under_her_row_lock_blocks_the_deletion(): void
    {
        $client = User::factory()->client()->create();
        $request = app(AccountDeletion::class)->request($client, DeletionReason::Privacy, null);

        // The other session holds her user row (as checkout / acceptance / ticket creation would) and opens a ticket.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from users where id = ? for update', [$client->id]);
        $other->table('support_tickets')->insert(['reference' => 'TK-LOCK', 'user_id' => $client->id, 'topic' => 'other', 'subject' => 'Hi',
            'status' => 'open', 'last_message_at' => now(), 'idempotency_key' => 'k', 'idempotency_fingerprint' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now()]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(AccountDeletionProcessor::class)->process($request->id, null, null));
        $other->commit();

        $outcome = app(AccountDeletionProcessor::class)->process($request->id, null, null);

        $this->assertSame(AccountDeletionProcessor::RESULT_BLOCKED, $outcome['result']);
        $this->assertSame(['open_tickets' => 1], $outcome['obligations']);
        $this->assertSame(UserStatus::Active, $client->refresh()->status);
    }

    public function test_a_withdraw_that_wins_the_request_row_stops_the_deletion(): void
    {
        $client = User::factory()->client()->create();
        $request = app(AccountDeletion::class)->request($client, DeletionReason::Privacy, null);

        // The other session is her withdraw: it holds the request row and closes it.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from account_deletion_requests where id = ? for update', [$request->id]);
        $other->table('account_deletion_requests')->where('id', $request->id)
            ->update(['status' => 'withdrawn', 'open_user_id' => null, 'withdrawn_at' => now()]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(AccountDeletionProcessor::class)->process($request->id, null, null));
        $other->commit();

        $outcome = app(AccountDeletionProcessor::class)->process($request->id, null, null);

        $this->assertSame(AccountDeletionProcessor::RESULT_SKIPPED, $outcome['result']);
        $this->assertSame(UserStatus::Active, $client->refresh()->status);
        $this->assertSame(DeletionRequestStatus::Withdrawn, AccountDeletionRequest::query()->find($request->id)->status);
    }

    public function test_two_runs_at_once_delete_the_account_once(): void
    {
        $client = User::factory()->client()->create();
        $request = app(AccountDeletion::class)->request($client, DeletionReason::Privacy, null);

        // The other session is a first run that already anonymised her and holds her row.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from users where id = ? for update', [$client->id]);
        $other->table('users')->where('id', $client->id)->update(['status' => 'deleted', 'name' => 'Deleted user', 'phone' => 'deleted-'.$client->id]);
        $other->table('account_deletion_requests')->where('id', $request->id)->update(['status' => 'processed', 'open_user_id' => null, 'processed_at' => now()]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(AccountDeletionProcessor::class)->process($request->id, null, null));
        $other->commit();

        $this->assertSame(AccountDeletionProcessor::RESULT_SKIPPED, app(AccountDeletionProcessor::class)->process($request->id, null, null)['result']);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'account_deleted')->count());
    }
}
