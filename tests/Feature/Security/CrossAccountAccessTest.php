<?php

namespace Tests\Feature\Security;

use App\Enums\BookingStatus;
use App\Enums\OfferStatus;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\User;
use App\Services\Offers\OfferSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * QA round 2026-11-19 (security): a second client, and a provider who is not part of the request, try every id-based
 * route on someone else's request, offers, booking and conversation. Reads must be 403/404, and writes must fail and
 * change nothing. The owners still reach their records.
 */
class CrossAccountAccessTest extends TestCase
{
    use BuildsBookings, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    private Provider $outsider;

    private User $mona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
        // Not a recipient of Sara's request: another city, so the open request never reaches her.
        $this->outsider = $this->provider('Outsider Studio', [$this->city()]);
        $this->mona = User::factory()->client()->create(['name' => 'Mona']);
    }

    private function denied(TestResponse $response, string $what): void
    {
        $this->assertContains($response->status(), [403, 404], "{$what} answered {$response->status()}: ".$response->getContent());
    }

    private function refused(TestResponse $response, string $what): void
    {
        $this->assertTrue($response->status() >= 400, "{$what} answered {$response->status()}: ".$response->getContent());
        $this->assertNotContains($response->status(), [500, 502, 503], "{$what} crashed: ".$response->getContent());
    }

    public function test_another_client_cannot_read_or_act_on_her_request_offers_and_booking(): void
    {
        $request = $this->created($this->openBody());
        $live = app(OfferSender::class)->send($this->huda, $request, (string) Str::uuid(), [
            'title' => 'Photos', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '800']], 'validity_days' => 7,
        ])[0];
        $conversation = $this->asClient()->postJson("/api/v1/client/service-requests/{$request->id}/conversation", ['provider_id' => $this->huda->id])
            ->assertSuccessful()->json('data.id');

        $mona = fn () => $this->actingAsApi($this->mona)->withHeader('Accept-Language', 'en');
        $this->denied($mona()->getJson("/api/v1/client/service-requests/{$request->id}"), 'GET request');
        $this->denied($mona()->getJson("/api/v1/client/service-requests/{$request->id}/offers"), 'GET request offers');
        $this->denied($mona()->getJson("/api/v1/client/offers/{$live->id}"), 'GET offer');
        $this->denied($mona()->getJson("/api/v1/client/conversations/{$conversation}"), 'GET conversation');
        $this->denied($mona()->getJson("/api/v1/client/conversations/{$conversation}/messages"), 'GET messages');
        $this->refused($mona()->postJson("/api/v1/client/conversations/{$conversation}/messages", ['body' => 'hi', 'client_uuid' => (string) Str::uuid()]), 'POST message');
        $this->refused($mona()->postJson("/api/v1/client/service-requests/{$request->id}/conversation", ['provider_id' => $this->huda->id]), 'open conversation');
        $this->refused($mona()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/client/offers/{$live->id}/accept"), 'accept offer');
        $this->refused($mona()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/client/offers/{$live->id}/payments", ['method' => 'card']), 'pay offer');
        $this->refused($mona()->postJson("/api/v1/client/offers/{$live->id}/reject"), 'reject offer');
        $this->refused($mona()->postJson("/api/v1/client/service-requests/{$request->id}/cancel"), 'cancel request');
        $this->assertSame(OfferStatus::Active, $live->fresh()->status);
        $this->assertSame('offers_received', $request->fresh()->status->value);

        // Booked: the booking is hers alone.
        $booking = $this->booking($this->noura);
        $this->denied($mona()->getJson("/api/v1/client/bookings/{$booking->id}"), 'GET booking');
        $this->refused($mona()->postJson("/api/v1/client/bookings/{$booking->id}/cancellation-requests", ['reason_code' => 'event_date_changed', 'reason_text' => 'x']), 'cancel booking');
        $this->assertSame(0, $booking->cancellationRequests()->count());
        $this->assertFalse($this->actingAsApi($this->mona)->getJson('/api/v1/client/bookings')->collect('data')->pluck('id')->contains($booking->id), 'booking listed to another client');

        // Owner sanity.
        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertOk();
    }

    public function test_a_provider_outside_the_request_cannot_reach_it_and_only_the_booked_one_runs_the_booking(): void
    {
        $booking = $this->booking($this->noura);
        $request = $booking->serviceRequest;

        $out = fn () => $this->asProvider($this->outsider);
        $this->denied($out()->getJson("/api/v1/provider/service-requests/{$request->id}"), 'outsider GET request');
        $this->refused($out()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/provider/service-requests/{$request->id}/offers", [
            'title' => 'x', 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => '1']], 'validity_days' => 7,
        ]), 'outsider sends offer');
        $this->refused($out()->postJson("/api/v1/provider/service-requests/{$request->id}/decline", ['decline_reason_id' => 1, 'explanation' => 'x']), 'outsider declines');
        $this->refused($out()->postJson("/api/v1/provider/service-requests/{$request->id}/conversation"), 'outsider opens chat');

        // Huda was a recipient but was not booked: no access to the booking.
        foreach ([$this->outsider, $this->huda] as $provider) {
            $who = $provider->display_name;
            $this->denied($this->asProvider($provider)->getJson("/api/v1/provider/bookings/{$booking->id}"), "{$who} GET booking");
            $this->at(self::EVENT_STARTS);
            $this->refused($this->startBooking($provider, $booking), "{$who} starts booking");
            $this->refused($this->asProvider($provider)->postJson("/api/v1/provider/bookings/{$booking->id}/cancellation-requests", ['reason_code' => 'emergency', 'reason_text' => 'x']), "{$who} cancels booking");
            $this->refused($this->asProvider($provider)->putJson("/api/v1/provider/bookings/{$booking->id}/team", ['member_ids' => []]), "{$who} sets team");
        }
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        // The booked provider can, and then only she completes it.
        $this->startBooking($this->noura, $booking)->assertOk();
        $this->refused($this->completeBooking($this->huda, $booking), 'huda completes booking');
        $this->completeBooking($this->noura, $booking)->assertOk();
    }

    public function test_a_client_cannot_review_a_booking_that_is_not_hers(): void
    {
        $booking = $this->completedBooking($this->noura);
        $this->refused($this->actingAsApi($this->mona)->postJson("/api/v1/client/bookings/{$booking->id}/review", ['rating' => 1, 'comment' => 'fake']), 'foreign review');
        $this->assertNull($booking->fresh()->review);
    }

    public function test_each_audience_is_kept_out_of_the_others_routes(): void
    {
        $booking = $this->booking($this->noura);
        $this->assertSame(403, $this->asClient()->getJson("/api/v1/provider/bookings/{$booking->id}")->status(), 'client on provider route');
        $this->assertSame(403, $this->asProvider($this->noura)->getJson("/api/v1/client/bookings/{$booking->id}")->status(), 'provider on client route');
        $this->assertSame(403, $this->asClient()->getJson('/api/v1/admin/bookings')->status(), 'client on admin route');
        $this->assertSame(403, $this->asProvider($this->noura)->getJson('/api/v1/admin/finance/overview')->status(), 'provider on admin route');
        // No token → 401 is checked in a clean request (the test app keeps the parsed JWT between requests).
    }

    public function test_no_token_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/client/bookings/1')->assertUnauthorized();
        $this->getJson('/api/v1/provider/bookings')->assertUnauthorized();
        $this->getJson('/api/v1/admin/finance/overview')->assertUnauthorized();
    }

    public function test_the_client_cannot_change_the_price_she_pays(): void
    {
        $request = $this->created($this->openBody());
        $offer = app(OfferSender::class)->send($this->noura, $request, (string) Str::uuid(), [
            'title' => 'Photos', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
        ])[0];
        $this->asClient()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/client/offers/{$offer->id}/accept", ['total' => '1.00', 'amount' => 1, 'price' => 1])->assertCreated();
        $booking = Booking::query()->sole();
        $this->assertSame('1000.00', number_format((float) $booking->total, 2, '.', ''), 'booking total follows the offer snapshot');
        $this->assertSame(OfferStatus::Selected, Offer::query()->find($offer->id)->status);
    }
}
