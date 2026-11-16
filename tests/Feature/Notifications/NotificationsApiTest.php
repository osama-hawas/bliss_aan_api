<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Jobs\DeliverNotification;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 8 §4 (D137): her own list only, pagination, read / read-all, unread count, text in the request language.
 */
class NotificationsApiTest extends TestCase
{
    use BuildsNotifications, BuildsServiceRequests, RefreshDatabase;

    private const LIST = '/api/v1/client/notifications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
    }

    private function give(User $user, int $n, NotificationEvent $event = NotificationEvent::BookingStarted): void
    {
        for ($i = 1; $i <= $n; $i++) {
            (new DeliverNotification($user->id, $event, ['name' => 'Noura Lens', 'reference' => "BK-{$i}", 'service' => ['ar' => 'تصوير', 'en' => 'Photography']],
                NotificationTargetType::Booking, $i, "test:{$event->value}:{$i}"))->handle(app(NotificationPreferenceService::class));
        }
    }

    private function as(User $user, string $lang = 'en'): static
    {
        return $this->actingAsApi($user)->withHeader('Accept-Language', $lang);
    }

    public function test_guest_and_wrong_audience_are_refused(): void
    {
        $this->getJson(self::LIST)->assertUnauthorized();
        $provider = $this->matchingProvider();
        $this->as($provider->user)->getJson(self::LIST)->assertForbidden();
        $this->as($this->client)->getJson('/api/v1/provider/notifications')->assertForbidden();
    }

    public function test_list_is_hers_only_newest_first_and_paginated(): void
    {
        $other = User::factory()->create(['type' => 'client']);
        $this->give($other, 2);
        $this->give($this->client, 20);

        $page = $this->as($this->client)->getJson(self::LIST.'?per_page=15')->assertOk();
        $page->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 20)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.unread_count', 20)
            ->assertJsonPath('data.0.event', 'booking_started')
            ->assertJsonPath('data.0.title', 'Service started')
            ->assertJsonPath('data.0.body', 'Noura Lens started booking BK-20.')
            ->assertJsonPath('data.0.target', ['type' => 'booking', 'id' => 20])
            ->assertJsonPath('data.0.read_at', null);
        $this->as($this->client)->getJson(self::LIST.'?page=2&per_page=15')->assertJsonCount(5, 'data')->assertJsonPath('data.4.target.id', 1);
        $this->as($this->client)->getJson(self::LIST.'?per_page=500')->assertJsonPath('meta.per_page', 50);
        $this->as($this->client, 'ar')->getJson(self::LIST)->assertJsonPath('data.0.title', 'بدأت الخدمة')
            ->assertJsonPath('data.0.body', 'بدأت Noura Lens تنفيذ الحجز BK-20.');
    }

    public function test_read_one_read_all_and_filters(): void
    {
        $this->give($this->client, 3);
        $ids = $this->as($this->client)->getJson(self::LIST)->json('data.*.id');

        $first = $this->as($this->client)->postJson(self::LIST."/{$ids[0]}/read")->assertOk()->assertJsonPath('meta.unread_count', 2)->json('data.read_at');
        $this->assertNotNull($first);
        $this->travel(5)->minutes();
        $this->as($this->client)->postJson(self::LIST."/{$ids[0]}/read")->assertOk()->assertJsonPath('data.read_at', $first); // idempotent

        $this->as($this->client)->getJson(self::LIST.'?filter[read]=false')->assertJsonCount(2, 'data');
        $this->as($this->client)->getJson(self::LIST.'?filter[read]=true')->assertJsonCount(1, 'data');
        $this->as($this->client)->getJson(self::LIST.'/unread-count')->assertJsonPath('data.unread_count', 2);

        $this->as($this->client)->postJson(self::LIST.'/read-all')->assertOk()->assertJsonPath('data.marked', 2)->assertJsonPath('data.unread_count', 0);
        $this->as($this->client)->postJson(self::LIST.'/read-all')->assertOk()->assertJsonPath('data.marked', 0);
        $this->as($this->client)->getJson(self::LIST.'?filter[read]=maybe')->assertUnprocessable();
    }

    public function test_another_users_notification_is_not_found(): void
    {
        $other = User::factory()->create(['type' => 'client']);
        $this->give($other, 1);
        $id = DB::table('notifications')->where('notifiable_id', $other->id)->value('id');

        $this->as($this->client)->postJson(self::LIST."/{$id}/read")->assertNotFound();
        $this->as($this->client)->postJson(self::LIST.'/read-all');
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->as($this->client)->postJson(self::LIST.'/not-a-uuid/read')->assertNotFound();
    }

    public function test_a_row_arriving_after_read_all_stays_unread(): void
    {
        $this->give($this->client, 1);
        $this->as($this->client)->postJson(self::LIST.'/read-all')->assertOk();
        $this->travel(1)->seconds();
        $this->give($this->client, 1, NotificationEvent::BookingCompleted);

        $this->as($this->client)->getJson(self::LIST.'/unread-count')->assertJsonPath('data.unread_count', 1);
    }

    public function test_prune_deletes_only_read_rows_older_than_180_days(): void
    {
        $this->give($this->client, 3);
        $ids = DB::table('notifications')->orderBy('id')->pluck('id');
        DB::table('notifications')->where('id', $ids[0])->update(['read_at' => now()->subDays(200), 'created_at' => now()->subDays(200)]); // read + old
        DB::table('notifications')->where('id', $ids[1])->update(['created_at' => now()->subDays(400)]);                                   // unread + old
        DB::table('notifications')->where('id', $ids[2])->update(['read_at' => now()]);                                                     // read + new

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$ids[1], $ids[2]], DB::table('notifications')->pluck('id')->all());
    }
}
