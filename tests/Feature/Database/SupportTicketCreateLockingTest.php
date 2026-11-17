<?php

namespace Tests\Feature\Database;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Support\SupportTickets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * D162 with two real MySQL sessions: same-key creates queue on the user row; the waiting one re-checks the key after
 * the first commits and replays it (no second ticket, no duplicate-key error). The unique (user_id, idempotency_key)
 * stays as the last guard (IdempotencyRace → replay) but cannot be reached by same-user creates while the row is held.
 */
class SupportTicketCreateLockingTest extends TestCase
{
    use UsesCommittedRows;

    private const BODY = ['topic' => 'payment', 'subject' => 'Charged twice', 'message' => 'Twice.'];

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

    public function test_a_same_key_create_waits_for_the_first_and_replays_it(): void
    {
        $sara = User::factory()->client()->create();
        $key = (string) Str::uuid();
        $fingerprint = hash('sha256', json_encode([...self::BODY, 'related_type' => null, 'related_id' => null]));

        // The other session is the first create: it holds her row and has not committed yet.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->table('users')->where('id', $sara->id)->lockForUpdate()->first();

        $this->shortLockWait();
        $this->assertWaits(fn () => app(SupportTickets::class)->create($sara, $key, self::BODY));

        // It stores its ticket with the key and commits; the waiting create then finds it and replays.
        $id = $other->table('support_tickets')->insertGetId([
            'reference' => 'TK-0000001', 'user_id' => $sara->id, 'topic' => 'payment', 'subject' => 'Charged twice',
            'status' => 'open', 'last_message_at' => now(), 'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $other->table('ticket_messages')->insert(['ticket_id' => $id, 'author_id' => $sara->id, 'body' => 'Twice.', 'is_internal' => false, 'created_at' => now(), 'updated_at' => now()]);
        $other->commit();

        [$ticket, $isNew] = app(SupportTickets::class)->create($sara, $key, self::BODY);

        $this->assertFalse($isNew);
        $this->assertSame($id, $ticket->id);
        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertSame(1, TicketMessage::query()->count());
    }

    public function test_the_lock_is_per_user(): void
    {
        [$sara, $huda] = User::factory()->client()->count(2)->create()->all();

        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->table('users')->where('id', $sara->id)->lockForUpdate()->first();

        $this->shortLockWait();
        [, $isNew] = app(SupportTickets::class)->create($huda, (string) Str::uuid(), self::BODY);

        $this->assertTrue($isNew);
        $other->rollBack();
    }
}
