<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\ProviderStatus;
use App\Models\DeclineReason;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Client history and cancellation, provider inbox, privacy and decline (§3, §4, §6.3, §6.4, D56, D60, D67, D68).
 */
class RequestLifecycleTest extends TestCase
{
    use BuildsServiceRequests, RefreshDatabase;

    private const CLIENT = '/api/v1/client/service-requests';

    private const PROVIDER = '/api/v1/provider/service-requests';

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    private function asClient(?User $client = null): static
    {
        return $this->actingAsApi($client ?? $this->client)->withHeader('Accept-Language', 'en');
    }

    private function asProvider(Provider $provider): static
    {
        return $this->actingAsApi($provider->user)->withHeader('Accept-Language', 'en');
    }

    private function reason(string $code = 'fully_booked'): DeclineReason
    {
        return DeclineReason::query()->where('code', $code)->sole();
    }

    // ── Client ──────────────────────────────────────────────────────────────────────────────

    public function test_client_list_filters_sorts_and_paginates_her_own_requests(): void
    {
        $open = $this->created($this->openBody(['event_date' => now('Asia/Riyadh')->addDays(20)->format('Y-m-d')]));
        $direct = $this->created($this->directBody($this->noura));
        $this->created($this->openBody([]));
        $other = User::factory()->client()->create();
        $this->submit($this->openBody(), as: $other)->assertCreated();

        $this->asClient()->getJson(self::CLIENT)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.address')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.history');

        $this->asClient()->getJson(self::CLIENT.'?filter[type]=direct')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $direct->id);
        $this->asClient()->getJson(self::CLIENT.'?sort=-starts_at&per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
        $this->asClient()->getJson(self::CLIENT.'?filter[status]=cancelled,declined')->assertJsonCount(0, 'data');
        $this->asClient()->getJson(self::CLIENT.'?filter[status]=bogus&sort=id')->assertJsonValidationErrors(['filter.status.0', 'sort'], 'errors');
    }

    public function test_client_cannot_see_another_clients_request(): void
    {
        $request = $this->created($this->openBody());
        $other = User::factory()->client()->create();

        $this->asClient($other)->getJson(self::CLIENT."/{$request->id}")->assertNotFound();
        $this->asClient($other)->postJson(self::CLIENT."/{$request->id}/cancel")->assertNotFound();
    }

    public function test_cancel_with_and_without_reason_closes_the_recipients(): void
    {
        $request = $this->created($this->openBody());

        $this->asClient()->postJson(self::CLIENT."/{$request->id}/cancel", ['reason' => 'Date changed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Date changed')
            ->assertJsonPath('data.history.1.event', 'request_cancelled')
            ->assertJsonPath('data.history.1.from_status', 'awaiting_offers');

        $this->assertSame(['closed', 'closed'], $request->recipients()->get()->map(fn ($r) => $r->status->value)->all());
        $this->assertNotNull($request->recipients()->first()->closed_at);

        $second = $this->created($this->openBody());
        $this->asClient()->postJson(self::CLIENT."/{$second->id}/cancel")->assertOk()->assertJsonPath('data.cancel_reason', null);
    }

    public function test_cancel_in_a_wrong_state(): void
    {
        $request = $this->created($this->openBody());
        $this->asClient()->postJson(self::CLIENT."/{$request->id}/cancel")->assertOk();

        $this->asClient()->postJson(self::CLIENT."/{$request->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    // ── Provider inbox ──────────────────────────────────────────────────────────────────────

    public function test_inbox_shows_only_her_requests_with_privacy_model_a(): void
    {
        $open = $this->created($this->openBody(['notes' => 'Two photographers please']));
        $direct = $this->created($this->directBody($this->noura));
        $notHers = $this->created($this->directBody($this->huda));

        $list = $this->asProvider($this->noura)->getJson(self::PROVIDER)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.attachments');
        $this->assertEqualsCanonicalizing([$open->id, $direct->id], array_column($list->json('data'), 'id'));

        $detail = $this->asProvider($this->noura)->getJson(self::PROVIDER."/{$open->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.request_status', 'awaiting_offers')
            ->assertJsonPath('data.district', 'Al Malqa')
            ->assertJsonPath('data.client', ['first_name' => 'Sara'])
            ->assertJsonPath('data.notes', 'Two photographers please')
            ->assertJsonPath('data.decline', null);

        $json = json_encode($detail->json());
        foreach (['Villa 12', '24.7136', '46.6753', 'Home', $this->client->phone, 'Al Harbi', 'Huda Studio'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertArrayNotHasKey('address', $detail->json('data'));
        $this->asProvider($this->noura)->getJson(self::PROVIDER."/{$notHers->id}")->assertNotFound();
    }

    public function test_provider_location_requests_show_no_district(): void
    {
        $offered = $this->noura->services()->sole();
        $offered->update(['at_provider_location' => true]);
        $request = $this->created($this->directBody($this->noura, ['execution_place' => 'provider_location', 'address' => null]));

        $this->asProvider($this->noura)->getJson(self::PROVIDER."/{$request->id}")->assertJsonPath('data.district', null);
    }

    public function test_seen_at_is_set_once(): void
    {
        $request = $this->created($this->directBody($this->noura));
        $this->asProvider($this->noura)->getJson(self::PROVIDER)->assertJsonPath('data.0.seen_at', null);

        $this->travelTo(now()->addMinute());
        $first = $this->asProvider($this->noura)->getJson(self::PROVIDER."/{$request->id}")->json('data.seen_at');
        $this->travelTo(now()->addHour());
        $this->asProvider($this->noura)->getJson(self::PROVIDER."/{$request->id}")->assertJsonPath('data.seen_at', $first);
        $this->assertNotNull($first);
    }

    public function test_inbox_filters_and_sorts(): void
    {
        $this->created($this->openBody());
        $direct = $this->created($this->directBody($this->noura));

        $this->asProvider($this->noura)->getJson(self::PROVIDER.'?filter[type]=direct')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $direct->id);
        $this->asProvider($this->noura)->getJson(self::PROVIDER.'?filter[status]=declined')->assertJsonCount(0, 'data');
        $this->asProvider($this->noura)->getJson(self::PROVIDER.'?sort=-received_at')->assertJsonPath('data.0.id', $direct->id);
        $this->asProvider($this->noura)->getJson(self::PROVIDER.'?sort=created_at')->assertJsonValidationErrors('sort', 'errors');
    }

    public function test_a_provider_who_is_not_active_cannot_open_the_inbox(): void
    {
        $this->created($this->directBody($this->noura));
        $this->noura->update(['status' => ProviderStatus::Suspended]);

        $this->asProvider($this->noura)->getJson(self::PROVIDER)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_decline_reasons_list(): void
    {
        $this->reason('other')->update(['is_active' => false]);

        $this->asProvider($this->noura)->getJson('/api/v1/provider/decline-reasons')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0', ['id' => $this->reason()->id, 'code' => 'fully_booked', 'name' => 'Already booked at this time']);
    }

    // ── Decline ─────────────────────────────────────────────────────────────────────────────

    public function test_declining_a_direct_request_declines_it(): void
    {
        $request = $this->created($this->directBody($this->noura));

        $this->asProvider($this->noura)->postJson(self::PROVIDER."/{$request->id}/decline", ['decline_reason_id' => $this->reason()->id, 'explanation' => 'Booked that night'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.request_status', 'declined')
            ->assertJsonPath('data.decline.reason.code', 'fully_booked')
            ->assertJsonPath('data.decline.explanation', 'Booked that night');

        $this->asClient()->getJson(self::CLIENT."/{$request->id}")
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.history.1.event', 'request_declined');
        // The internal recipient entry is not in her history.
        $this->assertCount(2, $this->asClient()->getJson(self::CLIENT."/{$request->id}")->json('data.history'));
    }

    public function test_declines_on_an_open_request_leave_it_awaiting_offers(): void
    {
        $request = $this->created($this->openBody());
        $body = ['decline_reason_id' => $this->reason()->id, 'explanation' => 'Not this time'];

        $this->asProvider($this->noura)->postJson(self::PROVIDER."/{$request->id}/decline", $body)->assertOk()->assertJsonPath('data.request_status', 'awaiting_offers');
        $this->asProvider($this->huda)->postJson(self::PROVIDER."/{$request->id}/decline", $body)->assertOk();

        $this->assertSame('awaiting_offers', $request->fresh()->status->value); // D244 (Q17): no transition
        $this->asClient()->getJson(self::CLIENT."/{$request->id}")->assertJsonPath('data.status', 'awaiting_offers');
    }

    public function test_decline_validation_and_state(): void
    {
        $request = $this->created($this->openBody());
        $url = self::PROVIDER."/{$request->id}/decline";

        $this->asProvider($this->noura)->postJson($url, [])->assertJsonValidationErrors(['decline_reason_id'], 'errors');
        // A plain decline still needs an explanation (D51); only a withdrawal may skip it (owner decision 2026-11-19).
        $this->asProvider($this->noura)->postJson($url, ['decline_reason_id' => $this->reason()->id])->assertJsonValidationErrors(['explanation'], 'errors');
        $this->reason('other')->update(['is_active' => false]);
        $this->asProvider($this->noura)->postJson($url, ['decline_reason_id' => $this->reason('other')->id, 'explanation' => 'x'])->assertJsonValidationErrors('decline_reason_id', 'errors');

        $this->asProvider($this->noura)->postJson($url, ['decline_reason_id' => $this->reason()->id, 'explanation' => 'x'])->assertOk();
        $this->asProvider($this->noura)->postJson($url, ['decline_reason_id' => $this->reason()->id, 'explanation' => 'x'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->asClient()->postJson(self::CLIENT."/{$request->id}/cancel")->assertOk();
        $this->asProvider($this->huda)->postJson($url, ['decline_reason_id' => $this->reason()->id, 'explanation' => 'x'])->assertStatus(409);
    }

    public function test_a_non_recipient_cannot_decline(): void
    {
        $request = $this->created($this->directBody($this->huda));

        $this->asProvider($this->noura)->postJson(self::PROVIDER."/{$request->id}/decline", ['decline_reason_id' => $this->reason()->id, 'explanation' => 'x'])->assertNotFound();
        $this->assertSame('awaiting_offers', ServiceRequest::query()->find($request->id)->status->value);
    }

    public function test_wrong_user_types(): void
    {
        $this->asProvider($this->noura)->getJson(self::CLIENT)->assertForbidden();
        $this->asClient()->getJson(self::PROVIDER)->assertForbidden();
        $this->asClient()->getJson('/api/v1/provider/decline-reasons')->assertForbidden();
    }
}
