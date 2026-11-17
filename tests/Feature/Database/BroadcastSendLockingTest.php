<?php

namespace Tests\Feature\Database;

use App\Enums\Audience;
use App\Enums\BroadcastStatus;
use App\Exceptions\ApiException;
use App\Jobs\SendBroadcastCampaign;
use App\Models\BroadcastCampaign;
use App\Models\User;
use App\Services\Content\BroadcastCampaigns;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * slice-20-contract.md D305 with two real MySQL sessions: a send holds the campaign row; a second send waits, then
 * answers 409 once the first committed — the campaign starts once.
 */
class BroadcastSendLockingTest extends TestCase
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

    public function test_a_second_send_waits_then_conflicts(): void
    {
        Bus::fake();
        User::factory()->client()->create();
        $admin = User::factory()->admin()->create();
        $campaign = BroadcastCampaign::query()->create([
            'title' => ['ar' => 'ع', 'en' => 'T'], 'body' => ['ar' => 'ب', 'en' => 'B'], 'app' => Audience::Client,
            'status' => BroadcastStatus::Draft, 'created_by' => $admin->id,
        ]);

        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from broadcast_campaigns where id = ? for update', [$campaign->id]);
        $other->table('broadcast_campaigns')->where('id', $campaign->id)->update(['status' => BroadcastStatus::Sending->value, 'recipients_count' => 1]);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(BroadcastCampaigns::class)->send($campaign, $admin, 1));
        $other->commit();

        try {
            app(BroadcastCampaigns::class)->send($campaign, $admin, 1);
            $this->fail('The second send went through.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status());
        }
        Bus::assertNotDispatched(SendBroadcastCampaign::class);
    }
}
