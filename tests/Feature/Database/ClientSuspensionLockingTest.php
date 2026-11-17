<?php

namespace Tests\Feature\Database;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\AccountStatusLog;
use App\Models\User;
use App\Services\Accounts\ClientAccounts;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * slice-15-contract.md §3.3 with two real MySQL sessions: a suspension holds the client row; a second suspension waits,
 * then answers 409 once the first committed — one status change, one status log.
 */
class ClientSuspensionLockingTest extends TestCase
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

    public function test_a_second_suspension_waits_then_conflicts(): void
    {
        $client = User::factory()->client()->create();
        $admin = User::factory()->admin()->create();

        // The other session is the first suspension: it holds the user row and commits the change.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from users where id = ? for update', [$client->id]);
        $other->table('users')->where('id', $client->id)->update(['status' => UserStatus::Suspended->value, 'status_reason' => 'first']);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(ClientAccounts::class)->suspend($client, $admin, 'second', null));
        $other->commit();

        try {
            app(ClientAccounts::class)->suspend($client, $admin, 'second', null);
            $this->fail('The second suspension went through.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status());
        }
        $this->assertSame('first', $client->refresh()->status_reason);
        $this->assertSame(0, AccountStatusLog::query()->count());
    }
}
