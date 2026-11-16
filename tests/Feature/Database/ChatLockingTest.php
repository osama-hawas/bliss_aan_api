<?php

namespace Tests\Feature\Database;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Provider;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatMessenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAvailability;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * slice-7-contract.md §4 with two real MySQL sessions: a send holds the conversation row; a retry of the same message
 * (same client_uuid) waits, then answers with the committed message instead of a second row.
 */
class ChatLockingTest extends TestCase
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

    public function test_a_retried_send_waits_and_replays_the_first(): void
    {
        $request = $this->created($this->openBody(['event_date' => '2026-12-05']));
        $conversation = app(ChatAccess::class)->openAsProvider($this->noura->user, $request->id);
        $uuid = (string) Str::uuid();
        $body = ['client_uuid' => $uuid, 'type' => 'text', 'body' => 'Hello'];

        // The other session is the first send: it holds the conversation row and writes the message.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from conversations where id = ? for update', [$conversation->id]);
        $other->table('messages')->insert([
            'conversation_id' => $conversation->id, 'sender_id' => $this->noura->user_id, 'type' => 'text', 'body' => 'Hello',
            'client_uuid' => $uuid, 'fingerprint' => hash('sha256', json_encode([$conversation->id, 'text', 'Hello', null, null])),
            'flagged' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(ChatMessenger::class)->send($this->noura->user, $conversation, $body));
        $other->commit();

        [$message, $isNew] = app(ChatMessenger::class)->send($this->noura->user, Conversation::query()->find($conversation->id), $body);
        $this->assertFalse($isNew);
        $this->assertSame(1, Message::query()->count());
        $this->assertSame('Hello', $message->body);
    }
}
