<?php

namespace Tests\Feature\Database;

use App\Enums\DeletionReason;
use App\Enums\ErrorCode;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\AccountDeletionRequest;
use App\Models\Media;
use App\Models\User;
use App\Services\Account\AccountDeletion;
use App\Services\Account\ProfileService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 12a with two real MySQL sessions: a deletion request holds her `users` row; a second send waits, then answers
 * 409 DELETION_PENDING instead of writing a second pending row (D151).
 */
class DeletionRequestLockingTest extends TestCase
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

    public function test_a_concurrent_second_request_waits_then_gets_409(): void
    {
        $client = User::factory()->client()->create();

        // The other session is the first send: it holds her user row and writes the pending request.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from users where id = ? for update', [$client->id]);
        $other->table('account_deletion_requests')->insert([
            'user_id' => $client->id, 'open_user_id' => $client->id, 'reason_code' => 'privacy', 'status' => 'pending',
            'user_type' => 'client', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(AccountDeletion::class)->request($client, DeletionReason::Other, null));
        $other->commit();

        try {
            app(AccountDeletion::class)->request($client, DeletionReason::Other, null);
            $this->fail('A second pending request was accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::DeletionPending, $e->errorCode);
        }
        $this->assertSame(1, AccountDeletionRequest::query()->count());
    }

    public function test_a_photo_change_waits_for_her_user_row(): void
    {
        $client = User::factory()->client()->create();
        $media = Media::query()->create([
            'collection' => UploadContext::Avatar->value, 'disk' => UploadContext::Avatar->visibility()->disk(), 'path' => 'avatar/x.jpg', 'mime' => 'image/jpeg',
            'size' => 100, 'visibility' => UploadContext::Avatar->visibility(), 'uploaded_by' => $client->id,
        ]);

        // The other session is a concurrent photo change holding her user row.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from users where id = ? for update', [$client->id]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(ProfileService::class)->setAvatar($client, $media->id));
        $other->commit();

        app(ProfileService::class)->setAvatar($client, $media->id);
        $this->assertSame(1, $client->refresh()->media()->where('collection', 'avatar')->count());
    }
}
