<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * For tests that need committed rows and a second MySQL session (`other_session`): rebuilds the `testing` schema
 * before and after, leaving the empty, fully migrated database the other tests expect.
 */
trait UsesCommittedRows
{
    protected function setUpCommittedRows(): void
    {
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh');
        config(['database.connections.other_session' => config('database.connections.'.config('database.default'))]);
        DB::purge('other_session');
    }

    protected function tearDownCommittedRows(): void
    {
        if (DB::connection('other_session')->transactionLevel() > 0) {
            DB::connection('other_session')->rollBack();
        }
        DB::purge('other_session');
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
    }

    /**
     * Makes this session give up a lock wait after one second (MySQL error 1205), to prove that it waits.
     */
    protected function shortLockWait(): void
    {
        DB::statement('set session innodb_lock_wait_timeout = 1');
    }

    /**
     * Asserts the call waited on a lock held by the other session (it timed out with 1205, nothing retried).
     */
    protected function assertWaits(callable $call): void
    {
        try {
            $call();
            $this->fail('The call did not wait for the lock held by the other session.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null, $e->getMessage());
        }
    }
}
