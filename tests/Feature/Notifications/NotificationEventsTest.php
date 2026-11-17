<?php

namespace Tests\Feature\Notifications;

use App\Enums\AdminRole;
use App\Enums\DocumentAppliesTo;
use App\Enums\ProviderDocumentStatus;
use App\Enums\ProviderStatus;
use App\Models\CancellationRequest;
use App\Models\Conversation;
use App\Models\DeclineReason;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Services\Bookings\OfferAcceptor;
use App\Services\Cancellations\BookingCancellations;
use App\Services\Offers\OfferSender;
use App\Services\Providers\ProviderReview;
use App\Services\Reviews\ReviewService;
use App\Services\ServiceRequests\ServiceRequestLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsNotifications;
use Tests\TestCase;

/**
 * Slice 8 §6 (D133, D134, D142): each approved event creates exactly one notification for the right recipient(s),
 * never for the actor, after the business transaction commits.
 */
class NotificationEventsTest extends TestCase
{
    use BuildsApplications, BuildsBookings, BuildsNotifications, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
        $this->client->update(['name' => 'Sara Al Harbi']);
    }

    private function sendOffer(Provider $provider, ServiceRequest $request)
    {
        return app(OfferSender::class)->send($provider, $request, (string) Str::uuid(), [
            'title' => 'Photography', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 3,
        ])[0];
    }

    private function decline(Provider $provider, ServiceRequest $request): void
    {
        app(ServiceRequestLifecycle::class)->decline($provider, $request, DeclineReason::query()->where('code', 'fully_booked')->sole(), 'Booked that night.');
    }

    public function test_direct_request_notifies_only_its_provider_with_the_client_first_name(): void
    {
        $this->device($this->noura->user, 'tok-noura');
        $request = $this->created($this->directBody($this->noura));

        $this->assertSame(['direct_request_received'], $this->events($this->noura->user));
        $this->assertSame([], $this->events($this->huda->user));
        $this->assertSame([], $this->events($this->client));
        $row = $this->row($this->noura->user, 'direct_request_received');
        $this->assertEquals(['type' => 'request', 'id' => $request->id], $row->data['target']);
        $this->assertSame('Sara', $row->data['params']['name']);
        $push = $this->pushesTo('tok-noura');
        $this->assertCount(1, $push);
        $this->assertStringContainsString('Sara', $push[0]->body);
        $this->assertStringNotContainsString('Harbi', $push[0]->body);
    }

    public function test_open_request_notifies_every_matched_provider(): void
    {
        $this->created($this->openBody());

        $this->assertSame(['open_request_received'], $this->events($this->noura->user));
        $this->assertSame(['open_request_received'], $this->events($this->huda->user));
        $this->assertSame([], $this->events($this->client));
    }

    public function test_client_cancel_notifies_providers_still_taking_part(): void
    {
        $request = $this->created($this->openBody());
        $this->decline($this->huda, $request); // Huda left the request: not told
        app(ServiceRequestLifecycle::class)->cancel($this->client, $request->fresh(), null);

        $this->assertSame(['open_request_received', 'request_cancelled'], $this->events($this->noura->user));
        $this->assertSame(['open_request_received'], $this->events($this->huda->user));
    }

    public function test_offer_received_goes_to_the_client_and_opens_the_offer(): void
    {
        $request = $this->created($this->openBody());
        $offer = $this->sendOffer($this->noura, $request);

        $this->assertSame(['offer_received'], $this->events($this->client));
        $this->assertEquals(['type' => 'offer', 'id' => $offer->id], $this->row($this->client, 'offer_received')->data['target']);
        $this->assertSame(['open_request_received'], $this->events($this->noura->user)); // never the actor
    }

    public function test_direct_decline_and_direct_withdrawal_notify_the_client(): void
    {
        $declined = $this->created($this->directBody($this->noura));
        $this->decline($this->noura, $declined);
        $this->assertSame(['request_declined'], $this->events($this->client));

        $withdrawn = $this->created($this->directBody($this->huda));
        $this->sendOffer($this->huda, $withdrawn);
        $this->decline($this->huda, $withdrawn->fresh());
        $this->assertSame(['request_declined', 'offer_received', 'offer_withdrawn'], $this->events($this->client));
    }

    public function test_open_request_decline_or_withdrawal_stays_internal(): void
    {
        $request = $this->created($this->openBody());
        $this->sendOffer($this->noura, $request);
        $this->decline($this->noura, $request->fresh());
        $this->decline($this->huda, $request->fresh());

        $this->assertSame(['offer_received'], $this->events($this->client)); // D76/D91, D245
    }

    public function test_offer_expiry_notifies_its_provider_once(): void
    {
        $request = $this->created($this->openBody());
        $this->sendOffer($this->noura, $request);
        $this->travel(4)->days();

        $this->artisan('offers:expire')->assertSuccessful();
        $this->artisan('offers:expire')->assertSuccessful();

        $this->assertSame(['open_request_received', 'offer_expired'], $this->events($this->noura->user));
        $this->assertSame(['offer_received'], $this->events($this->client));
    }

    public function test_acceptance_notifies_the_winner_and_providers_with_a_live_offer(): void
    {
        $this->matchingProvider('Lama Events'); // no offer: not told
        $booking = $this->booking($this->noura); // Noura's offer is accepted
        $request = $booking->serviceRequest;
        $this->assertSame(['open_request_received', 'offer_accepted'], $this->events($this->noura->user));
        $this->assertEquals(['type' => 'booking', 'id' => $booking->id], $this->row($this->noura->user, 'offer_accepted')->data['target']);
        $this->assertSame(['open_request_received'], $this->events($this->huda->user));

        // A second request where Huda also offered.
        $second = $this->created($this->openBody(['event_date' => '2026-12-08']));
        $this->sendOffer($this->huda, $second);
        $winning = $this->sendOffer($this->noura, $second);
        app(OfferAcceptor::class)->accept($this->client, $winning, (string) Str::uuid());
        $this->assertContains('offer_not_selected', $this->events($this->huda->user));
        $this->assertEquals(['type' => 'request', 'id' => $second->id], $this->row($this->huda->user, 'offer_not_selected')->data['target']);
        $this->assertNotContains('offer_accepted', $this->events($this->huda->user));
        $this->assertSame(0, collect($this->events($this->client))->filter(fn ($e) => str_starts_with($e, 'offer_accepted'))->count());
        unset($request);
    }

    public function test_start_and_complete_notify_the_client_once(): void
    {
        $booking = $this->completedBooking($this->noura);
        $this->completeBooking($this->noura, $booking)->assertOk(); // idempotent repeat

        $this->assertSame(['offer_received', 'booking_started', 'booking_completed'], $this->events($this->client));
        $this->assertEquals(['type' => 'booking', 'id' => $booking->id], $this->row($this->client, 'booking_completed')->data['target']);
    }

    public function test_approved_cancellation_tells_the_requester_and_the_other_party(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $c = CancellationRequest::query()->where('booking_id', $booking->id)->sole();
        app(BookingCancellations::class)->decide($this->staff(AdminRole::OperationsSupport), $c, true, 'Checked.', null);

        $this->assertContains('cancellation_approved', $this->events($this->client));
        $this->assertNotContains('booking_cancelled', $this->events($this->client));
        $this->assertContains('booking_cancelled', $this->events($this->noura->user));
        $this->assertNotContains('cancellation_approved', $this->events($this->noura->user));
    }

    public function test_rejected_provider_inability_tells_only_the_provider(): void
    {
        $booking = $this->booking($this->noura);
        $this->providerCancel($this->noura, $booking)->assertCreated();
        $c = CancellationRequest::query()->where('booking_id', $booking->id)->sole();
        app(BookingCancellations::class)->decide($this->staff(AdminRole::OperationsSupport), $c, false, 'Please attend.', null);

        $this->assertContains('cancellation_rejected', $this->events($this->noura->user));
        $this->assertSame(['offer_received'], $this->events($this->client));
    }

    public function test_review_and_reply(): void
    {
        $booking = $this->completedBooking($this->noura);
        $review = app(ReviewService::class)->submit($this->client, $booking, 5, 'Wonderful photos', []);
        $this->assertContains('review_received', $this->events($this->noura->user));
        $this->assertEquals(['type' => 'reviews', 'id' => $review->id], $this->row($this->noura->user, 'review_received')->data['target']);

        app(ReviewService::class)->reply($this->noura, Review::query()->findOrFail($review->id), 'Thank you, Sara!');
        $this->assertContains('review_replied', $this->events($this->client));
        $this->assertEquals(['type' => 'booking', 'id' => $booking->id], $this->row($this->client, 'review_replied')->data['target']);
    }

    public function test_chat_message_is_push_only_without_the_text(): void
    {
        $this->device($this->client, 'tok-sara');
        $this->device($this->noura->user, 'tok-noura');
        $request = $this->created($this->directBody($this->noura));
        $id = $this->actingAsApi($this->noura->user)->postJson("/api/v1/provider/service-requests/{$request->id}/conversation")->assertSuccessful()->json('data.id');
        $uuid = (string) Str::uuid();
        $send = fn () => $this->actingAsApi($this->noura->user)->postJson("/api/v1/provider/conversations/{$id}/messages",
            ['client_uuid' => $uuid, 'type' => 'text', 'body' => 'My secret price is 900'])->assertSuccessful();
        $send();
        $send(); // replay: no second push

        $this->assertSame([], $this->events($this->client)); // D142: no list row
        $pushes = $this->pushesTo('tok-sara');
        $this->assertCount(1, $pushes);
        $this->assertSame('chat_message', $pushes[0]->data['event']);
        $this->assertSame('conversation', $pushes[0]->data['target_type']);
        $this->assertSame((string) $id, $pushes[0]->data['target_id']);
        $this->assertSame('conversation-'.$id, $pushes[0]->collapseKey);
        $this->assertStringNotContainsString('900', $pushes[0]->body);
        $this->assertStringContainsString('Noura Lens', $pushes[0]->body);
        $this->assertCount(0, array_filter($this->pushesTo('tok-noura'), fn ($m) => $m->data['event'] === 'chat_message'));
        $this->assertSame(1, Conversation::query()->count());
    }

    public function test_application_updates_notify_the_applicant(): void
    {
        $admin = $this->staff(AdminRole::SuperAdmin);
        $review = app(ProviderReview::class);

        $submitted = $this->application();
        $review->requestCompletion($submitted, $admin, ['documents'], 'Please add your ID.', null);
        $this->assertSame(['application_needs_completion'], $this->events($submitted->user));

        $type = $this->documentType(DocumentAppliesTo::Individual);
        $other = $this->application();
        $doc = ProviderDocument::query()->create(['provider_id' => $other->id, 'document_type_id' => $type->id,
            'media_id' => $this->uploaded($other->user)->id, 'status' => ProviderDocumentStatus::Pending]);
        $review->rejectDocument($other, $doc, $admin, 'Unreadable.', null);
        $this->assertSame(['application_document_rejected'], $this->events($other->user));

        $review->suspend($this->huda, $admin, 'Complaints.', null);
        $review->reactivate($this->huda->fresh(), $admin, 'Resolved.', null);
        $this->assertSame(['application_suspended', 'application_reactivated'], $this->events($this->huda->user));
        $this->assertEquals(['type' => 'provider_account', 'id' => null], $this->row($this->huda->user, 'application_suspended')->data['target']);
        $this->assertSame(ProviderStatus::Active, $this->huda->fresh()->status);
    }
}
