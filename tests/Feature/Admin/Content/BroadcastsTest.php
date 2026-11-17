<?php

namespace Tests\Feature\Admin\Content;

use App\Enums\AdminRole;
use App\Enums\BroadcastStatus;
use App\Enums\NotificationPreferenceKey;
use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Jobs\SendBroadcastCampaign;
use App\Models\BroadcastCampaign;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsContent;
use Tests\Concerns\BuildsNotifications;
use Tests\TestCase;

/**
 * Slice 20 (D305, D306): campaigns reach the chosen app and city through the Slice 8 Notifier (E40); the push respects
 * the `promotions` preference, the row is always stored, a campaign is sent once.
 */
class BroadcastsTest extends TestCase
{
    use BuildsCatalog, BuildsContent, BuildsNotifications, RefreshDatabase;

    private const URL = '/api/v1/admin/broadcasts';

    private function draft(User $admin, array $attributes = []): int
    {
        return $this->actingAsApi($admin)->postJson(self::URL, ['title' => $this->both('Eid'), 'body' => $this->both('Offers'), 'audience' => 'client', ...$attributes])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    public function test_send_reaches_active_clients_of_the_city_and_respects_promotions(): void
    {
        $admin = $this->admin();
        $riyadh = $this->city();
        $jeddah = $this->city('Jeddah', 'جدة');
        $sara = User::factory()->client()->create(['city_id' => $riyadh->id]);
        $muted = User::factory()->client()->create(['city_id' => $riyadh->id]);
        User::factory()->client()->create(['city_id' => $jeddah->id]);
        User::factory()->client()->create(['city_id' => $riyadh->id, 'status' => UserStatus::Suspended, 'status_reason' => 'x']);
        $this->device($sara, 'tok-sara');
        $this->device($muted, 'tok-muted');
        NotificationPreference::query()->create(['user_id' => $muted->id, 'key' => NotificationPreferenceKey::Promotions, 'enabled' => false]);

        $id = $this->draft($admin, ['city_id' => $riyadh->id]);
        $this->actingAsApi($admin)->getJson(self::URL."/{$id}/audience")->assertOk()->assertJsonPath('data.count', 2);
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/send", ['expected_count' => 3])->assertStatus(409)->assertJsonPath('code', 'AUDIENCE_CHANGED');

        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/send", ['expected_count' => 2])->assertOk()->assertJsonPath('data.recipients_count', 2);

        $campaign = BroadcastCampaign::query()->find($id);
        $this->assertSame(BroadcastStatus::Sent, $campaign->status); // sync queue in tests
        $this->assertSame(['broadcast'], $this->events($sara));
        $this->assertSame(['broadcast'], $this->events($muted)); // the row is kept (D135)
        $this->assertCount(1, $this->pushesTo('tok-sara'));
        $this->assertCount(0, $this->pushesTo('tok-muted'));
        $this->assertEquals(['type' => 'broadcast', 'id' => $id], $this->row($sara, 'broadcast')->data['target']);

        // Sent once: no second send, no edit, no cancel.
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/send", ['expected_count' => 2])->assertStatus(409);
        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", ['title' => $this->both('x'), 'body' => $this->both('y'), 'audience' => 'client'])->assertStatus(409);
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/cancel", ['reason' => 'late'])->assertStatus(409);
    }

    public function test_provider_audience_is_active_providers_covering_the_city(): void
    {
        $admin = $this->admin();
        $riyadh = $this->city();
        $this->provider('Covering', [$riyadh]);
        $this->provider('Elsewhere');
        $this->provider('Pending', [$riyadh], ['status' => ProviderStatus::Submitted, 'activated_at' => null]);

        $id = $this->draft($admin, ['audience' => 'provider', 'city_id' => $riyadh->id]);
        $this->actingAsApi($admin)->getJson(self::URL."/{$id}/audience")->assertOk()->assertJsonPath('data.count', 1);
        $all = $this->draft($admin, ['audience' => 'provider']);
        $this->actingAsApi($admin)->getJson(self::URL."/{$all}/audience")->assertOk()->assertJsonPath('data.count', 2);
    }

    public function test_translations_recipients_schedule_and_cancel(): void
    {
        $admin = $this->admin();
        $id = $this->draft($admin, ['title' => ['ar' => 'عيد'], 'body' => ['ar' => 'عروض']]);
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/send", ['expected_count' => 0])->assertStatus(409)->assertJsonPath('code', 'TRANSLATION_MISSING');
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/schedule", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertStatus(409);

        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", ['title' => $this->both('Eid'), 'body' => $this->both('Offers'), 'audience' => 'client'])->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/send", ['expected_count' => 0])->assertStatus(409)->assertJsonPath('code', 'NO_RECIPIENTS');

        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/schedule", ['scheduled_at' => now()->subMinute()->toIso8601String()])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/schedule", ['scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/cancel")->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/cancel", ['reason' => 'wrong date'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'wrong date');
    }

    public function test_due_scheduled_campaigns_start_from_the_command(): void
    {
        Bus::fake();
        $admin = $this->admin();
        User::factory()->client()->create();
        $id = $this->draft($admin);
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/schedule", ['scheduled_at' => now()->addMinutes(5)->toIso8601String()])->assertOk();

        $this->artisan('broadcasts:dispatch-due')->assertSuccessful();
        $this->assertSame(BroadcastStatus::Scheduled, BroadcastCampaign::query()->find($id)->status);

        $this->travel(6)->minutes();
        $this->artisan('broadcasts:dispatch-due')->assertSuccessful();
        $campaign = BroadcastCampaign::query()->find($id);
        $this->assertSame(BroadcastStatus::Sending, $campaign->status);
        $this->assertSame(1, $campaign->recipients_count);
        Bus::assertDispatched(SendBroadcastCampaign::class, fn ($job) => $job->campaignId === $id);
    }

    public function test_broadcasts_need_notifications_broadcast(): void
    {
        $this->actingAsApi($this->admin(AdminRole::StoreContentOfficer))->getJson(self::URL)->assertForbidden();
    }
}
