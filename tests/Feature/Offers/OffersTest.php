<?php

namespace Tests\Feature\Offers;

use App\Enums\AdminRole;
use App\Enums\OfferStatus;
use App\Enums\ProviderStatus;
use App\Models\ActivityEvent;
use App\Models\DeclineReason;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 5b offers (slice-5b-contract.md): send, supersede (D6), validity and expiry (D5), withdraw + decline (D91),
 * client list/compare/details, cancel closes offers, admin view, privacy between competitors, idempotency.
 */
class OffersTest extends TestCase
{
    use BuildsApplications, BuildsServiceRequests, RefreshDatabase;

    private const PROVIDER = '/api/v1/provider/service-requests';

    private const CLIENT = '/api/v1/client/service-requests';

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    private function body(array $overrides = []): array
    {
        return [
            'title' => 'Wedding photography',
            'items' => [
                ['description' => 'Photography 3h', 'quantity' => 1, 'unit_price' => '1500.50'],
                ['description' => 'Album', 'quantity' => 2, 'unit_price' => 250],
            ],
            'validity_days' => 7,
            'notes' => 'Includes edited photos.',
            ...$overrides,
        ];
    }

    private function send(Provider $provider, ServiceRequest $request, array $body = [], ?string $key = null): TestResponse
    {
        return $this->actingAsApi($provider->user)->withHeader('Accept-Language', 'en')
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::PROVIDER."/{$request->id}/offers", $this->body($body));
    }

    private function asClient(?User $client = null): static
    {
        return $this->actingAsApi($client ?? $this->client)->withHeader('Accept-Language', 'en');
    }

    private function decline(Provider $provider, ServiceRequest $request): TestResponse
    {
        $reason = DeclineReason::query()->where('code', 'fully_booked')->sole();

        return $this->actingAsApi($provider->user)->withHeader('Accept-Language', 'en')
            ->postJson(self::PROVIDER."/{$request->id}/decline", ['decline_reason_id' => $reason->id, 'explanation' => 'Booked that night.']);
    }

    // ── Send ────────────────────────────────────────────────────────────────────────────────

    public function test_sending_an_offer_snapshots_it_and_moves_the_states(): void
    {
        $this->travelTo(now()->startOfMinute());
        $request = $this->created($this->openBody());

        $res = $this->send($this->noura, $request)->assertCreated()
            ->assertJsonPath('message', 'Offer sent.')
            ->assertJsonPath('data.status', 'offer_sent')
            ->assertJsonPath('data.request_status', 'offers_received')
            ->assertJsonPath('data.current_offer.status', 'active')
            ->assertJsonPath('data.current_offer.total', ['amount' => '2000.50', 'currency' => 'SAR'])
            ->assertJsonPath('data.current_offer.items.0.line_total.amount', '1500.50')
            ->assertJsonPath('data.current_offer.items.1.line_total.amount', '500.00')
            ->assertJsonPath('data.current_offer.validity_days', 7)
            ->assertJsonPath('data.current_offer.valid_until', now()->addDays(7)->utc()->toIso8601ZuluString())
            ->assertJsonPath('data.current_offer.is_quick', false)
            ->assertJsonCount(1, 'data.offers');
        $this->assertMatchesRegularExpression('/^Q-\d{7}$/', $res->json('data.current_offer.reference'));

        $this->assertSame('offers_received', $request->fresh()->status->value);
        $this->assertDatabaseHas('activity_events', ['subject_id' => $request->id, 'event' => 'offer_sent', 'from_status' => 'awaiting_offers', 'to_status' => 'offers_received']);

        // Snapshot: later price changes never rewrite the offer.
        $this->noura->services()->update(['custom_price' => 99]);
        $this->assertSame('2000.50', Offer::query()->sole()->total);
    }

    public function test_a_second_offer_supersedes_the_first_and_the_request_stays_offers_received(): void
    {
        $request = $this->created($this->openBody());
        $first = $this->send($this->noura, $request)->json('data.current_offer.id');
        $this->send($this->huda, $request)->assertCreated();

        $res = $this->send($this->noura, $request, ['title' => 'Revised', 'validity_days' => 3])->assertCreated()
            ->assertJsonPath('data.current_offer.title', 'Revised')
            ->assertJsonCount(2, 'data.offers')
            ->assertJsonPath('data.offers.1.status', 'superseded');
        $this->assertNotSame($first, $res->json('data.current_offer.id'));
        $this->assertSame(OfferStatus::Superseded, Offer::query()->find($first)->status);
        $this->assertSame(1, ActivityEvent::query()->where('event', 'offer_sent')->where('from_status', 'awaiting_offers')->count());
    }

    public function test_validation(): void
    {
        $request = $this->created($this->openBody());
        $this->send($this->noura, $request, ['validity_days' => 5, 'title' => '', 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['validity_days', 'title', 'items']);
        $this->send($this->noura, $request, ['items' => [['description' => 'x', 'quantity' => 0, 'unit_price' => '1.234']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity', 'items.0.unit_price']);
        $this->send($this->noura, $request, ['items' => [['description' => 'Free', 'quantity' => 1, 'unit_price' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->send($this->noura, $request, ['items' => [['description' => 'Big', 'quantity' => 2, 'unit_price' => '999999.99']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->flushHeaders()->actingAsApi($this->noura->user)->postJson(self::PROVIDER."/{$request->id}/offers", $this->body())
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
        $this->assertSame(0, Offer::query()->count());
    }

    public function test_idempotent_replay_and_key_reuse(): void
    {
        $request = $this->created($this->openBody());
        $key = (string) Str::uuid();
        $id = $this->send($this->noura, $request, key: $key)->assertCreated()->json('data.current_offer.id');
        $this->send($this->noura, $request, key: $key)->assertOk()->assertJsonPath('data.current_offer.id', $id);
        $this->send($this->noura, $request, ['title' => 'Other'], $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, Offer::query()->count());
    }

    public function test_who_may_send(): void
    {
        $request = $this->created($this->directBody($this->noura));
        // Not a recipient → 404; client token → 403.
        $this->send($this->huda, $request)->assertNotFound();
        $this->asClient()->postJson(self::PROVIDER."/{$request->id}/offers", $this->body())->assertForbidden();
        // Provider suspended at provider level → 409 (activeProvider).
        $this->noura->update(['status' => ProviderStatus::Suspended]);
        $this->send($this->noura, $request)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_no_offer_after_decline_or_cancellation(): void
    {
        $request = $this->created($this->openBody());
        $this->decline($this->huda, $request)->assertOk();
        $this->send($this->huda, $request)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->asClient()->postJson(self::CLIENT."/{$request->id}/cancel")->assertOk();
        $this->send($this->noura, $request)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_event_passed_does_not_block_an_offer(): void
    {
        $request = $this->created($this->openBody());
        $this->travel(9)->days();
        $this->assertTrue($request->fresh()->eventPassed());
        $this->send($this->noura, $request)->assertCreated();
    }

    // ── Expiry ──────────────────────────────────────────────────────────────────────────────

    public function test_an_offer_past_its_validity_is_expired_and_the_command_stores_it(): void
    {
        $request = $this->created($this->openBody());
        $this->send($this->noura, $request, ['validity_days' => 3])->assertCreated();
        $this->travel(3)->days();
        $this->travel(1)->seconds();

        $this->asClient()->getJson(self::CLIENT."/{$request->id}/offers")->assertOk()->assertJsonPath('data.0.status', 'expired');
        $this->asClient()->getJson(self::CLIENT."/{$request->id}")->assertJsonPath('data.offers_count', 0);
        $this->assertSame(OfferStatus::Active, Offer::query()->sole()->status);

        $this->artisan('offers:expire')->assertSuccessful();
        $this->assertSame(OfferStatus::Expired, Offer::query()->sole()->status);
        $this->assertSame('offers_received', $request->fresh()->status->value); // no request transition

        // She may send a fresh offer after expiry; the expired one is kept as expired.
        $this->send($this->noura, $request)->assertCreated()->assertJsonPath('data.offers.1.status', 'expired');
    }

    // ── Withdraw + decline (D91) ────────────────────────────────────────────────────────────

    public function test_withdrawing_on_an_open_request_is_internal_and_keeps_the_request(): void
    {
        $request = $this->created($this->openBody());
        $this->send($this->noura, $request)->assertCreated();

        $this->decline($this->noura, $request)->assertOk()
            ->assertJsonPath('message', 'Offer withdrawn and request declined.')
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.current_offer.status', 'withdrawn')
            ->assertJsonPath('data.decline.reason.code', 'fully_booked');

        $offer = Offer::query()->sole();
        $this->assertSame(OfferStatus::Withdrawn, $offer->status);
        $this->assertNotNull($offer->withdrawn_at);
        $this->assertSame('offers_received', $request->fresh()->status->value);
        $this->assertDatabaseHas('activity_events', ['event' => 'offer_withdrawn', 'visibility' => 'internal']);
        $this->assertDatabaseHas('activity_events', ['event' => 'recipient_declined', 'from_status' => 'offer_sent', 'visibility' => 'internal']);

        // Client: the offer and the internal entries are gone from her view (D76).
        $this->asClient()->getJson(self::CLIENT."/{$request->id}/offers")->assertOk()->assertJsonCount(0, 'data');
        $this->asClient()->getJson("/api/v1/client/offers/{$offer->id}")->assertNotFound();
        $events = collect($this->asClient()->getJson(self::CLIENT."/{$request->id}")->json('data.history'))->pluck('event');
        $this->assertNotContains('offer_withdrawn', $events);
        $this->assertNotContains('recipient_declined', $events);

        // Declined: no second withdraw and no new offer.
        $this->decline($this->noura, $request)->assertStatus(409);
        $this->send($this->noura, $request)->assertStatus(409);
    }

    public function test_withdrawing_on_a_direct_request_declines_the_request(): void
    {
        $request = $this->created($this->directBody($this->noura));
        $this->send($this->noura, $request)->assertCreated();
        $this->decline($this->noura, $request)->assertOk()->assertJsonPath('data.request_status', 'declined');

        $this->assertSame('declined', $request->fresh()->status->value);
        $events = collect($this->asClient()->getJson(self::CLIENT."/{$request->id}")->json('data.history'))->pluck('event');
        $this->assertContains('request_declined', $events);
        $this->assertContains('offer_withdrawn', $events);
    }

    public function test_withdraw_needs_a_reason_and_an_explanation(): void
    {
        $request = $this->created($this->openBody());
        $this->send($this->noura, $request)->assertCreated();
        $this->actingAsApi($this->noura->user)->postJson(self::PROVIDER."/{$request->id}/decline", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['decline_reason_id', 'explanation']);
        $this->assertSame(OfferStatus::Active, Offer::query()->sole()->status);
    }

    // ── Client ──────────────────────────────────────────────────────────────────────────────

    public function test_client_lists_compares_and_opens_offers_without_competitor_leaks(): void
    {
        $request = $this->created($this->openBody());
        $this->send($this->noura, $request, ['items' => [['description' => 'A', 'quantity' => 1, 'unit_price' => 900]]]);
        $this->send($this->huda, $request, ['items' => [['description' => 'B', 'quantity' => 1, 'unit_price' => 700]]]);
        $superseded = $this->send($this->huda, $request, ['items' => [['description' => 'B2', 'quantity' => 1, 'unit_price' => 600]]])
            ->json('data.offers.1.id');

        $list = $this->asClient()->getJson(self::CLIENT."/{$request->id}/offers?sort=total")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.total.amount', '600.00')
            ->assertJsonPath('data.0.provider.display_name', 'Huda Studio')
            ->assertJsonPath('data.1.total.amount', '900.00');
        $this->assertArrayNotHasKey('idempotency_key', $list->json('data.0'));
        $this->asClient()->getJson(self::CLIENT."/{$request->id}")->assertJsonPath('data.offers_count', 2)->assertJsonPath('data.status', 'offers_received');
        $this->asClient()->getJson(self::CLIENT)->assertJsonPath('data.0.offers_count', 2);

        $id = $list->json('data.1.id');
        $this->asClient()->getJson("/api/v1/client/offers/{$id}")->assertOk()->assertJsonPath('data.items.0.description', 'A')
            ->assertJsonPath('data.service_request_id', $request->id);
        $this->asClient()->getJson("/api/v1/client/offers/{$superseded}")->assertNotFound();
        $this->asClient()->getJson(self::CLIENT."/{$request->id}/offers?sort=bogus")->assertUnprocessable();

        // Another client: 404. Competitor provider: sees only her own offers.
        $other = User::factory()->client()->create();
        $this->asClient($other)->getJson("/api/v1/client/offers/{$id}")->assertNotFound();
        $this->asClient($other)->getJson(self::CLIENT."/{$request->id}/offers")->assertNotFound();
        $huda = $this->actingAsApi($this->huda->user)->getJson(self::PROVIDER."/{$request->id}")->assertOk();
        $this->assertSame(['B2', 'B'], collect($huda->json('data.offers'))->pluck('items.0.description')->all());
        $inbox = $this->actingAsApi($this->huda->user)->getJson(self::PROVIDER)->assertOk();
        $this->assertSame('600.00', $inbox->json('data.0.current_offer.total.amount'));
        $this->assertArrayNotHasKey('items', $inbox->json('data.0.current_offer'));
    }

    public function test_cancelling_the_request_closes_its_offers(): void
    {
        $request = $this->created($this->openBody());
        $this->send($this->noura, $request)->assertCreated();
        $this->asClient()->postJson(self::CLIENT."/{$request->id}/cancel")->assertOk();

        $offer = Offer::query()->sole();
        $this->assertSame(OfferStatus::Closed, $offer->status);
        $this->assertNotNull($offer->closed_at);
        $this->asClient()->getJson(self::CLIENT."/{$request->id}/offers")->assertJsonPath('data.0.status', 'closed');
        $this->decline($this->noura, $request)->assertStatus(409);
    }

    // ── Admin ───────────────────────────────────────────────────────────────────────────────

    public function test_admin_detail_lists_every_offer(): void
    {
        $request = $this->created($this->openBody());
        $this->send($this->noura, $request);
        $this->send($this->noura, $request, ['title' => 'Revised']);
        $this->send($this->huda, $request);
        $this->decline($this->huda, $request);

        $res = $this->actingAsApi($this->staff(AdminRole::OperationsSupport))->getJson("/api/v1/admin/service-requests/{$request->id}")->assertOk()
            ->assertJsonCount(3, 'data.offers');
        $this->assertEqualsCanonicalizing(['active', 'superseded', 'withdrawn'], collect($res->json('data.offers'))->pluck('status')->all());
        $this->assertNotNull($res->json('data.offers.0.provider.display_name'));
    }
}
