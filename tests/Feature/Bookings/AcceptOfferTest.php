<?php

namespace Tests\Feature\Bookings;

use App\Enums\AdminRole;
use App\Enums\OfferStatus;
use App\Enums\ProviderStatus;
use App\Enums\RecipientStatus;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Offers\OfferSender;
use App\Services\Settings\AppSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsAvailability;
use Tests\TestCase;

/**
 * Slice 5c (slice-5c-contract.md, D4, D107–D113): accepting an offer confirms a booking at once. Time frozen at
 * 2026-12-01 12:00 Riyadh; events on 2026-12-05 19:00 Riyadh (16:00Z), 180 minutes unless stated.
 */
class AcceptOfferTest extends TestCase
{
    use BuildsApplications, BuildsAvailability, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    private function request(string $time = '19:00', int $minutes = 180, string $date = '2026-12-05'): ServiceRequest
    {
        return $this->created($this->openBody(['event_date' => $date, 'start_time' => $time, 'duration_minutes' => $minutes]));
    }

    private function sendOffer(Provider $provider, ServiceRequest $request, string $price = '1000', int $days = 7): Offer
    {
        return app(OfferSender::class)->send($provider, $request, (string) Str::uuid(), [
            'title' => "Offer by {$provider->display_name}", 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $price]], 'validity_days' => $days,
        ])[0];
    }

    private function accept(Offer $offer, ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAsApi($as ?? $this->client)->withHeader('Accept-Language', 'en')
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson("/api/v1/client/offers/{$offer->id}/accept");
    }

    private function buffer(int $minutes): void
    {
        app(AppSettings::class)->set('bookings.buffer_minutes', $minutes);
    }

    // ── Success ─────────────────────────────────────────────────────────────────────────────

    public function test_accepting_confirms_the_booking_and_settles_every_other_offer(): void
    {
        $request = $this->request();
        $old = $this->sendOffer($this->noura, $request, '900');
        $winner = $this->sendOffer($this->noura, $request, '1200.50'); // supersedes $old
        $loser = $this->sendOffer($this->huda, $request, '800');
        $third = $this->matchingProvider('Reem Art');
        $request->recipients()->create(['provider_id' => $third->id, 'status' => RecipientStatus::New]);

        $res = $this->accept($winner)->assertCreated()
            ->assertJsonPath('message', 'Booking confirmed.')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'not_required')
            ->assertJsonPath('data.total', ['amount' => '1200.50', 'currency' => 'SAR'])
            ->assertJsonPath('data.starts_at', '2026-12-05T16:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-12-05T19:00:00Z')
            ->assertJsonPath('data.provider.display_name', 'Noura Lens')
            ->assertJsonPath('data.offer.status', 'selected')
            ->assertJsonPath('data.address.details', 'Villa 12, King Fahd Rd')
            ->assertJsonPath('data.service_request.id', $request->id);
        $this->assertMatchesRegularExpression('/^BK-\d{7}$/', $res->json('data.reference'));

        $this->assertSame('confirmed', $request->fresh()->status->value);
        $this->assertSame(OfferStatus::Selected, $winner->fresh()->status);
        $this->assertSame(OfferStatus::NotSelected, $loser->fresh()->status);
        $this->assertSame(OfferStatus::Superseded, $old->fresh()->status);
        $recipients = $request->recipients()->get()->keyBy('provider_id');
        $this->assertSame(RecipientStatus::OfferSent, $recipients[$this->noura->id]->status);
        $this->assertSame(RecipientStatus::Closed, $recipients[$this->huda->id]->status);
        $this->assertSame(RecipientStatus::Closed, $recipients[$third->id]->status);
        $this->assertDatabaseHas('activity_events', ['subject_id' => $request->id, 'event' => 'booking_confirmed', 'from_status' => 'offers_received', 'to_status' => 'confirmed', 'visibility' => 'all']);

        // Snapshot: later changes on the request never rewrite the booking (D107).
        $request->update(['address' => ['details' => 'changed']]);
        $this->assertSame('Villa 12, King Fahd Rd', Booking::query()->sole()->address['details']);

        // Client views.
        $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.booking.offer_id', $winner->id);
        $statuses = collect($this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}/offers")->json('data'))->pluck('status', 'id');
        $this->assertSame('selected', $statuses[$winner->id]);
        $this->assertSame('not_selected', $statuses[$loser->id]);
        $this->actingAsApi($this->client)->getJson('/api/v1/client/bookings')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_winning_provider_sees_the_booking_and_address_others_are_closed(): void
    {
        $request = $this->request();
        $winner = $this->sendOffer($this->noura, $request);
        $this->sendOffer($this->huda, $request);
        $this->accept($winner)->assertCreated();
        $booking = Booking::query()->sole();

        $this->asProvider($this->noura)->getJson("/api/v1/provider/service-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.booking.id', $booking->id)
            ->assertJsonPath('data.address.details', 'Villa 12, King Fahd Rd')
            ->assertJsonPath('data.current_offer.status', 'selected');
        $detail = $this->asProvider($this->noura)->getJson("/api/v1/provider/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.client.first_name', 'Sara')
            ->assertJsonPath('data.address.lat', 24.7136);
        $this->assertStringNotContainsString($this->client->phone, $detail->getContent());
        $this->asProvider($this->noura)->getJson('/api/v1/provider/bookings')->assertOk()->assertJsonCount(1, 'data');
        $this->asProvider($this->noura)->getJson('/api/v1/provider/calendar?from=2026-12-01&to=2026-12-31')->assertOk()
            ->assertJsonPath('data.bookings.0.id', $booking->id)->assertJsonCount(0, 'data.requests');

        $huda = $this->asProvider($this->huda)->getJson("/api/v1/provider/service-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.booking', null)
            ->assertJsonPath('data.current_offer.status', 'not_selected');
        $this->assertArrayNotHasKey('address', $huda->json('data'));
        $this->asProvider($this->huda)->getJson("/api/v1/provider/bookings/{$booking->id}")->assertNotFound();
        $this->assertFalse(Gate::forUser($this->huda->user)->allows('viewAttachments', $request->fresh()));
        $this->assertTrue(Gate::forUser($this->noura->user)->allows('viewAttachments', $request->fresh())); // D52

        $admin = $this->actingAsApi($this->staff(AdminRole::OperationsSupport))->getJson("/api/v1/admin/service-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.booking.reference', $booking->reference)
            ->assertJsonPath('data.booking.provider.display_name', 'Noura Lens')
            ->assertJsonPath('data.booking.payment_status', 'not_required');
        $this->assertEqualsCanonicalizing(['selected', 'not_selected'], collect($admin->json('data.offers'))->pluck('status')->all());
    }

    // ── Authorization and validation ────────────────────────────────────────────────────────

    public function test_only_the_owning_client_can_accept(): void
    {
        $offer = $this->sendOffer($this->noura, $this->request());
        $this->accept($offer, as: User::factory()->client()->create())->assertNotFound();
        $this->actingAsApi($this->noura->user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/client/offers/{$offer->id}/accept")->assertForbidden();
        $this->flushHeaders()->actingAsApi($this->client)->postJson("/api/v1/client/offers/{$offer->id}/accept")
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_a_withdrawn_or_superseded_offer_is_not_found(): void
    {
        $request = $this->request();
        $old = $this->sendOffer($this->noura, $request);
        $this->sendOffer($this->noura, $request);
        $this->accept($old)->assertNotFound();
    }

    // ── Validity (D111) ─────────────────────────────────────────────────────────────────────

    public function test_expired_offer(): void
    {
        $offer = $this->sendOffer($this->noura, $this->request(), days: 3);
        $this->travel(3)->days();
        $this->accept($offer)->assertStatus(409)->assertJsonPath('code', 'OFFER_EXPIRED');
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_request_no_longer_open(): void
    {
        $request = $this->request();
        $offer = $this->sendOffer($this->noura, $request);
        $this->actingAsApi($this->client)->postJson("/api/v1/client/service-requests/{$request->id}/cancel")->assertOk();
        $this->accept($offer)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_event_already_started(): void
    {
        $offer = $this->sendOffer($this->noura, $this->request(), days: 14);
        $this->travelTo(now()->setDateTime(2026, 12, 5, 16, 0)); // exactly starts_at (16:00Z)
        $this->accept($offer)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->travelTo(now()->setDateTime(2026, 12, 5, 15, 59));
        $this->accept($offer)->assertCreated();
    }

    public function test_suspended_provider(): void
    {
        $offer = $this->sendOffer($this->noura, $this->request());
        $this->noura->update(['status' => ProviderStatus::Suspended]);
        $this->accept($offer)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    // ── Conflicts (D108–D110) ───────────────────────────────────────────────────────────────

    public function test_provider_booking_conflict_and_the_overlapping_offer_stays_open(): void
    {
        $first = $this->request('19:00');
        $second = $this->request('21:00', 60); // overlaps 21:00–22:00
        $a = $this->sendOffer($this->noura, $first);
        $b = $this->sendOffer($this->noura, $second);
        $this->accept($a)->assertCreated();

        $this->asProvider($this->noura)->getJson("/api/v1/provider/service-requests/{$second->id}")->assertJsonPath('data.calendar_conflict', true);
        $this->assertSame(OfferStatus::Active, $b->fresh()->status); // not closed automatically (D110)
        $this->accept($b)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->assertSame('offers_received', $second->fresh()->status->value);
    }

    public function test_availability_block_conflict(): void
    {
        $offer = $this->sendOffer($this->noura, $this->request());
        $this->storeBlock($this->noura, '2026-12-05T18:30:00Z', '2026-12-05T20:00:00Z');
        $this->accept($offer)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    }

    public function test_buffer_zero_allows_back_to_back_and_a_buffer_refuses_it(): void
    {
        $first = $this->request('19:00', 120);   // 16:00Z–18:00Z
        $second = $this->request('21:00', 60);   // 18:00Z–19:00Z, touching
        $third = $this->request('22:30', 60);    // 19:30Z–20:30Z, 30 min after the second
        [$a, $b, $c] = [$this->sendOffer($this->noura, $first), $this->sendOffer($this->noura, $second), $this->sendOffer($this->noura, $third)];

        $this->assertSame(0, (int) app(AppSettings::class)->get('bookings.buffer_minutes', 0));
        $this->accept($a)->assertCreated();
        $this->accept($b)->assertCreated(); // half-open: touching is fine at buffer 0

        $this->buffer(30);
        $this->accept($c)->assertCreated(); // exactly 30 min apart: still fine
        Booking::query()->where('offer_id', $c->id)->delete(); // free the slot for the next check (test only)
        $this->buffer(31);
        $this->travel(1)->seconds();
        $c2 = Offer::query()->find($c->id);
        $c2->update(['status' => OfferStatus::Active]);
        $third->update(['status' => 'offers_received']);
        $third->recipients()->where('provider_id', $this->noura->id)->update(['status' => 'offer_sent']);
        $this->accept($c2)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    }

    public function test_confirmed_bookings_block_direct_requests_and_count_in_unavailable_dates(): void
    {
        $this->accept($this->sendOffer($this->noura, $this->request('19:00', 300)))->assertCreated(); // 19:00–24:00 Riyadh

        $this->submit($this->directAt($this->noura, '2026-12-05', '20:00'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->submit($this->directAt($this->noura, '2026-12-05', '18:00'))->assertCreated(); // 18:00–19:00 touches, buffer 0
        // 16:45–18:45 ends 15 min before the booking: fine with a 15-minute buffer, refused with 20 (D108).
        $this->buffer(20);
        $this->submit($this->directAt($this->noura, '2026-12-05', '16:45', 120))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->buffer(15);
        $this->submit($this->directAt($this->noura, '2026-12-05', '16:45', 120))->assertCreated();
        $this->buffer(0);

        $dates = fn () => $this->getJson("/api/v1/shared/providers/{$this->noura->id}/availability?from=2026-12-05&to=2026-12-05")->assertOk()->json('data.unavailable_dates');
        $this->assertSame([], $dates()); // partial day only (D92)
        $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T16:00:00Z'); // 00:00–19:00 Riyadh
        $this->assertSame(['2026-12-05'], $dates()); // block + booking cover the whole day
    }

    // ── Idempotency and uniqueness (D112) ───────────────────────────────────────────────────

    public function test_idempotent_replay_and_second_acceptance(): void
    {
        $request = $this->request();
        $a = $this->sendOffer($this->noura, $request);
        $b = $this->sendOffer($this->huda, $request);
        $key = (string) Str::uuid();

        $id = $this->accept($a, $key)->assertCreated()->json('data.id');
        $this->accept($a, $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->accept($b, $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->accept($a)->assertStatus(409)->assertJsonPath('code', 'ALREADY_BOOKED');
        $this->accept($b)->assertStatus(409)->assertJsonPath('code', 'ALREADY_BOOKED');
        $this->assertSame(1, Booking::query()->count());
    }

    public function test_database_unique_constraints(): void
    {
        $request = $this->request();
        $offer = $this->sendOffer($this->noura, $request);
        $this->accept($offer)->assertCreated();
        $row = Booking::query()->sole()->getAttributes();
        unset($row['id']);

        foreach ([['reference' => 'BK-0000001', 'idempotency_key' => 'k1'], ['reference' => 'BK-0000002', 'idempotency_key' => 'k2', 'service_request_id' => $this->request('10:00', 60)->id]] as $i => $changes) {
            try {
                Booking::query()->insert([...$row, ...$changes]);
                $this->fail("Insert {$i} should violate a unique key.");
            } catch (UniqueConstraintViolationException $e) {
                $this->assertStringContainsString($i === 0 ? 'service_request_id' : 'offer_id', $e->getMessage());
            }
        }
    }
}
