<?php

namespace Tests\Concerns;

use App\Integrations\Payments\FakePaymentGateway;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\Availability\ProviderAvailability;
use App\Services\Bookings\BookingReferenceGenerator;
use App\Services\Bookings\OfferAcceptor;
use App\Services\Notifications\Notifier;
use App\Services\Offers\OfferSender;
use App\Services\Payments\PaymentSettings;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Slice 10 helpers on top of the 5a market (time frozen at 2026-12-01 12:00 Riyadh; events on 2026-12-05).
 * Fake card tokens: see FakePaymentGateway (0002 declined, 9995 insufficient funds, 3155 3-D Secure, 0119 timeout,
 * 0051 refund fails).
 */
trait BuildsPayments
{
    use BuildsAvailability;

    protected function requirePayment(bool $on = true): void
    {
        app(AppSettings::class)->set(PaymentSettings::BOOKING_PAYMENT_REQUIRED, $on);
        $this->intentTtl(30); // D191 seed (tests do not run AppSettingSeeder)
    }

    protected function intentTtl(?int $minutes): void
    {
        app(AppSettings::class)->set(PaymentSettings::INTENT_TTL_MINUTES, $minutes);
    }

    protected function eventRequest(string $time = '19:00', int $minutes = 180, string $date = '2026-12-05'): ServiceRequest
    {
        return $this->created($this->openBody(['event_date' => $date, 'start_time' => $time, 'duration_minutes' => $minutes]));
    }

    protected function offerFrom(Provider $provider, ?ServiceRequest $request = null, string $price = '1000'): Offer
    {
        return app(OfferSender::class)->send($provider, $request ?? $this->eventRequest(), (string) Str::uuid(), [
            'title' => "Offer by {$provider->display_name}", 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $price]],
            'validity_days' => 7,
        ])[0];
    }

    protected function cardToken(string $last4 = '4242', string $brand = 'visa', int $month = 12, int $year = 2030): string
    {
        return FakePaymentGateway::token($brand, $last4, $month, $year);
    }

    /**
     * Drops headers and the JWT kept from earlier requests in the same test (withHeader / actingAsApi persist).
     */
    protected function asGuest(): static
    {
        $this->flushHeaders();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /**
     * A partial OfferAcceptor (real constructor dependencies) whose accept() throws — fulfilment errors on purpose.
     */
    protected function failingAcceptor(\Throwable $error): void
    {
        $mock = \Mockery::mock(OfferAcceptor::class, [app(ActivityRecorder::class), app(DeadlockRetry::class), app(ProviderAvailability::class),
            app(BookingReferenceGenerator::class), app(Notifier::class)])->makePartial();
        $mock->shouldReceive('accept')->andThrow($error);
        $this->app->instance(OfferAcceptor::class, $mock);
    }

    protected function asClient(?User $as = null): static
    {
        return $this->actingAsApi($as ?? $this->client)->withHeader('Accept-Language', 'en');
    }

    protected function pay(Offer $offer, array $body = [], ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->asClient($as)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson("/api/v1/client/offers/{$offer->id}/payments", $body ?: ['method' => 'card', 'token' => $this->cardToken()]);
    }

    protected function paid(Offer $offer, string $last4 = '4242'): Payment
    {
        $id = $this->pay($offer, ['method' => 'card', 'token' => $this->cardToken($last4)])->assertCreated()->json('data.id');

        return Payment::query()->findOrFail($id);
    }

    /**
     * Delivers a signed fake webhook.
     */
    protected function webhook(string $type, string $gatewayReference, ?string $failureCode = null, ?string $eventId = null): TestResponse
    {
        [$body, $headers] = FakePaymentGateway::webhook($type, $gatewayReference, $failureCode, $eventId);

        return $this->call('POST', '/webhooks/payments/fake', [], [], [], $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json']), $body);
    }
}
