<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Integrations\Push\PushMessage;
use App\Integrations\Push\PushNotifier;
use App\Integrations\Push\PushResult;
use App\Jobs\DeliverNotification;
use App\Jobs\SendPushNotification;
use App\Models\Provider;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationRenderer;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 8 §5, §7, §8 (D135, D139, D141): preferences, after-commit, duplicates, device token lifecycle, retries,
 * failure isolation and payload privacy. Push goes through the fake driver: no real FCM delivery here.
 */
class PushDeliveryTest extends TestCase
{
    use BuildsNotifications, BuildsServiceRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
    }

    private function notify(User $user, NotificationEvent $event, string $key = 'k1'): void
    {
        app(Notifier::class)->send($user, $event, ['name' => 'Noura Lens', 'reference' => 'BK-1'], NotificationTargetType::Booking, 7, $key);
    }

    public function test_switched_off_key_keeps_the_row_but_stops_the_push_and_transactional_events_always_push(): void
    {
        $this->device($this->client, 'tok');
        app(NotificationPreferenceService::class)->update($this->client, ['offers' => false, 'messages' => false, 'reminders' => false, 'promotions' => false]);

        $this->notify($this->client, NotificationEvent::OfferReceived, 'a');
        $this->notify($this->client, NotificationEvent::BookingStarted, 'b');

        $this->assertSame(['offer_received', 'booking_started'], $this->events($this->client));
        $this->assertSame(['booking_started'], array_map(fn (PushMessage $m) => $m->data['event'], $this->pushesTo('tok')));
    }

    public function test_only_active_devices_with_a_token_get_a_push(): void
    {
        $this->device($this->client, 'tok-a');
        $this->device($this->client, 'tok-b');
        $this->device($this->client, 'tok-revoked', ['revoked_at' => now()]);
        $this->device($this->client, '', ['fcm_token' => null]);
        $other = User::factory()->create(['type' => 'client']);
        $this->device($other, 'tok-other');

        $this->notify($this->client, NotificationEvent::BookingStarted);

        $this->assertEqualsCanonicalizing(['tok-a', 'tok-b'], array_map(fn (PushMessage $m) => $m->token, $this->pushes()->sent));
    }

    public function test_nothing_is_sent_when_the_transaction_rolls_back(): void
    {
        $this->device($this->client, 'tok');
        try {
            DB::transaction(function () {
                $this->notify($this->client, NotificationEvent::BookingStarted);
                throw new RuntimeException('business rule failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->events($this->client));
        $this->assertSame([], $this->pushes()->sent);
    }

    public function test_sent_after_commit_and_never_to_the_actor(): void
    {
        $this->device($this->client, 'tok');
        DB::transaction(function () {
            $this->notify($this->client, NotificationEvent::BookingStarted);
            $this->assertSame([], $this->events($this->client)); // not yet
        });
        $this->assertSame(['booking_started'], $this->events($this->client));

        app(Notifier::class)->send($this->client, NotificationEvent::BookingCompleted, [], NotificationTargetType::Booking, 7, 'self', $this->client);
        $this->assertSame(['booking_started'], $this->events($this->client));
    }

    public function test_a_rerun_with_the_same_key_stores_and_pushes_nothing_more(): void
    {
        $this->device($this->client, 'tok');
        $this->notify($this->client, NotificationEvent::BookingStarted, 'same');
        $this->notify($this->client, NotificationEvent::BookingStarted, 'same');
        (new DeliverNotification($this->client->id, NotificationEvent::ChatMessage, [], NotificationTargetType::Conversation, 3, 'chat:1'))->handle(app(NotificationPreferenceService::class));
        (new DeliverNotification($this->client->id, NotificationEvent::ChatMessage, [], NotificationTargetType::Conversation, 3, 'chat:1'))->handle(app(NotificationPreferenceService::class));

        $this->assertSame(['booking_started'], $this->events($this->client));
        $this->assertCount(2, $this->pushesTo('tok')); // one booking push + one chat push
    }

    public function test_invalid_token_is_cleared_and_other_devices_keep_theirs(): void
    {
        $bad = $this->device($this->client, 'tok-bad');
        $good = $this->device($this->client, 'tok-good');
        $this->pushes()->results['tok-bad'] = PushResult::InvalidToken;

        $this->notify($this->client, NotificationEvent::BookingStarted);

        $this->assertNull($bad->fresh()->fcm_token);
        $this->assertSame('tok-good', $good->fresh()->fcm_token);
        $this->assertSame(['booking_started'], $this->events($this->client)); // the row is untouched
    }

    public function test_temporary_failure_is_retried_with_backoff_up_to_three_attempts(): void
    {
        $device = $this->device($this->client, 'tok');
        $this->pushes()->results['tok'] = PushResult::Retry;
        $job = fn () => new SendPushNotification($device->id, 'tok', $this->client->id, NotificationEvent::BookingStarted, [], NotificationTargetType::Booking, 7, null, 'c');

        $first = $job()->withFakeQueueInteractions();
        $first->handle(app(PushNotifier::class), app(NotificationRenderer::class));
        $first->assertReleased(10);

        $second = $job()->withFakeQueueInteractions();
        $second->job->attempts = 2;
        $second->handle(app(PushNotifier::class), app(NotificationRenderer::class));
        $second->assertReleased(60);

        $last = $job()->withFakeQueueInteractions();
        $last->job->attempts = 3;
        $last->handle(app(PushNotifier::class), app(NotificationRenderer::class));
        $last->assertNotReleased();
        $last->assertNotFailed();
        $this->assertSame('tok', $device->fresh()->fcm_token);
    }

    public function test_a_push_driver_crash_never_breaks_the_business_action(): void
    {
        $this->device($this->noura()->user, 'tok');
        $this->app->instance(PushNotifier::class, new class implements PushNotifier
        {
            public function send(PushMessage $message): PushResult
            {
                throw new RuntimeException('FCM down');
            }
        });

        $this->submit($this->directBody($this->noura()))->assertCreated();

        $this->assertSame(['direct_request_received'], $this->events($this->noura()->user));
    }

    public function test_push_text_follows_the_users_language_and_data_carries_ids_only(): void
    {
        $this->client->update(['locale' => 'en']);
        $this->device($this->client, 'tok');
        app(Notifier::class)->send($this->client, NotificationEvent::OfferReceived,
            ['name' => 'Noura Lens', 'reference' => 'RN-9', 'service' => ['ar' => 'تصوير', 'en' => 'Photography']], NotificationTargetType::Offer, 12, 'x');

        [$push] = $this->pushesTo('tok');
        $this->assertSame('New offer', $push->title);
        $this->assertSame('Noura Lens sent you an offer for Photography (RN-9).', $push->body);
        $this->assertSame(['notification_id', 'event', 'target_type', 'target_id'], array_keys($push->data));
        $this->assertSame('offer', $push->data['target_type']);
        $this->assertSame('12', $push->data['target_id']);

        $this->client->update(['locale' => 'ar']);
        app(Notifier::class)->send($this->client, NotificationEvent::OfferReceived,
            ['name' => 'Noura Lens', 'reference' => 'RN-9', 'service' => ['ar' => 'تصوير', 'en' => 'Photography']], NotificationTargetType::Offer, 12, 'y');
        $this->assertSame('عرض جديد', $this->pushesTo('tok')[1]->title);
    }

    private ?Provider $noura = null;

    private function noura(): Provider
    {
        return $this->noura ??= $this->matchingProvider('Noura Lens');
    }
}
