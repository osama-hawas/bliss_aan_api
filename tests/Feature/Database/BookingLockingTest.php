<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Services\Bookings\OfferAcceptor;
use App\Services\Offers\OfferSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAvailability;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * D112 with two real MySQL sessions. The other session plays the first of two racing acceptances; this session must
 * wait on the lock it holds (proved by a 1 s lock wait timeout), then decide on what it committed:
 *  - same request: the second acceptance answers ALREADY_BOOKED;
 *  - two requests of one provider at overlapping times: the provider lock serializes them → SLOT_UNAVAILABLE.
 */
class BookingLockingTest extends TestCase
{
    use BuildsAvailability, UsesCommittedRows;

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function other()
    {
        return DB::connection('other_session');
    }

    private function request(string $time, int $minutes = 120): ServiceRequest
    {
        return $this->created($this->openBody(['event_date' => '2026-12-05', 'start_time' => $time, 'duration_minutes' => $minutes]));
    }

    private function sendOffer(Provider $provider, ServiceRequest $request): Offer
    {
        return app(OfferSender::class)->send($provider, $request, (string) Str::uuid(), [
            'title' => 'Offer', 'items' => [['description' => 'Photo', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
        ])[0];
    }

    private function accept(Offer $offer): array
    {
        return app(OfferAcceptor::class)->accept($this->client, $offer, (string) Str::uuid());
    }

    /**
     * What the other session's acceptance writes for `$offer`, inside its open transaction.
     */
    private function otherBooks(Offer $offer, ServiceRequest $request): void
    {
        $this->other()->table('bookings')->insert([
            'reference' => 'BK-'.random_int(1000000, 9999999), 'service_request_id' => $request->id, 'offer_id' => $offer->id,
            'client_id' => $this->client->id, 'provider_id' => $offer->provider_id, 'service_id' => $request->service_id,
            'execution_place' => 'event_location', 'starts_at' => $request->starts_at, 'ends_at' => $request->ends_at,
            'title' => 'Offer', 'total' => '1000.00', 'status' => 'confirmed', 'payment_status' => 'not_required',
            'confirmed_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('0', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->other()->table('service_requests')->where('id', $request->id)->update(['status' => 'confirmed']);
        $this->other()->table('offers')->where('id', $offer->id)->update(['status' => 'selected']);
    }

    private function assertApiError(ErrorCode $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code->value}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_same_request_second_acceptance_waits_then_already_booked(): void
    {
        $request = $this->request('19:00');
        $a = $this->sendOffer($this->noura, $request);
        $b = $this->sendOffer($this->huda, $request);

        // The other session accepts $a: it holds the client lock (first in the D112 order) and writes the booking.
        $this->other()->beginTransaction();
        $this->other()->select('select id from users where id = ? for update', [$this->client->id]);
        $this->other()->select('select id from service_requests where id = ? for update', [$request->id]);
        $this->otherBooks($a, $request);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->accept($b));
        $this->other()->commit();

        $this->assertApiError(ErrorCode::AlreadyBooked, fn () => $this->accept($b));
        $this->assertSame(1, Booking::query()->count());
    }

    public function test_overlapping_requests_of_one_provider_are_serialized_by_her_lock(): void
    {
        $first = $this->request('19:00');          // 16:00Z–18:00Z
        $second = $this->request('20:00', 60);     // 17:00Z–18:00Z, overlaps
        $a = $this->sendOffer($this->noura, $first);
        $b = $this->sendOffer($this->noura, $second);

        // Another client's acceptance of $a holds the provider lock (last in the D112 order) and books her.
        $this->other()->beginTransaction();
        $this->other()->select('select id from providers where id = ? for update', [$this->noura->id]);
        $this->otherBooks($a, $first);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->accept($b));
        $this->other()->commit();

        $this->assertApiError(ErrorCode::SlotUnavailable, fn () => $this->accept($b));
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame('offers_received', $second->fresh()->status->value);
    }

    public function test_an_existing_booking_row_alone_answers_already_booked(): void
    {
        $request = $this->request('19:00');
        $a = $this->sendOffer($this->noura, $request);
        // A booking row exists although the request row still says offers_received: the re-check sees it.
        $this->otherBooks($a, $request);
        DB::table('service_requests')->where('id', $request->id)->update(['status' => 'offers_received']);
        DB::table('offers')->where('id', $a->id)->update(['status' => 'active']);

        $this->assertApiError(ErrorCode::AlreadyBooked, fn () => $this->accept($a));
        $this->assertSame(1, Booking::query()->count());
    }
}
