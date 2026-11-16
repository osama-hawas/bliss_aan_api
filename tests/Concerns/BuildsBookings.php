<?php

namespace Tests\Concerns;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use App\Services\Bookings\OfferAcceptor;
use App\Services\Offers\OfferSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Slice 6 helpers: a confirmed booking through the real 5b/5c services. Time frozen at 2026-12-01 12:00 Riyadh
 * (BuildsAvailability); the event is 2026-12-05 19:00 Riyadh = 16:00Z, 180 minutes.
 */
trait BuildsBookings
{
    use BuildsAvailability;

    protected const EVENT_STARTS = '2026-12-05T16:00:00Z';

    protected function booking(Provider $provider, ?User $client = null): Booking
    {
        $request = $this->created($this->openBody(['event_date' => '2026-12-05', 'start_time' => '19:00', 'duration_minutes' => 180]));
        $offer = app(OfferSender::class)->send($provider, $request, (string) Str::uuid(), [
            'title' => 'Photography', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
        ])[0];

        return app(OfferAcceptor::class)->accept($client ?? $this->client, $offer, (string) Str::uuid())[0];
    }

    protected function at(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc));
    }

    protected function asClient(?User $client = null): static
    {
        return $this->actingAsApi($client ?? $this->client)->withHeader('Accept-Language', 'en');
    }

    protected function startBooking(Provider $provider, Booking $booking): TestResponse
    {
        return $this->asProvider($provider)->postJson("/api/v1/provider/bookings/{$booking->id}/start");
    }

    protected function completeBooking(Provider $provider, Booking $booking): TestResponse
    {
        return $this->asProvider($provider)->postJson("/api/v1/provider/bookings/{$booking->id}/complete");
    }

    /**
     * A booking moved to completed through the API.
     */
    protected function completedBooking(Provider $provider): Booking
    {
        $booking = $this->booking($provider);
        $this->at(self::EVENT_STARTS);
        $this->startBooking($provider, $booking)->assertOk();
        $this->completeBooking($provider, $booking)->assertOk();

        return $booking->refresh();
    }

    protected function clientCancel(Booking $booking, array $body = []): TestResponse
    {
        return $this->asClient()->postJson("/api/v1/client/bookings/{$booking->id}/cancellation-requests",
            [...['reason_code' => 'event_date_changed', 'reason_text' => 'We moved the wedding.'], ...$body]);
    }

    protected function providerCancel(Provider $provider, Booking $booking, array $body = []): TestResponse
    {
        return $this->asProvider($provider)->postJson("/api/v1/provider/bookings/{$booking->id}/cancellation-requests",
            [...['reason_code' => 'emergency', 'reason_text' => 'Family emergency.'], ...$body]);
    }
}
