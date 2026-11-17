<?php

namespace Tests\Feature\Payments;

use App\Enums\AdminRole;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsStaff;
use Tests\TestCase;

/**
 * Slice 10 (slice-10-contract.md §10.2, D192): payments and refunds lists for the dashboard.
 */
class AdminPaymentsTest extends TestCase
{
    use BuildsPayments, BuildsStaff, RefreshDatabase;

    private const URL = '/api/v1/admin/payments';

    private User $admin;

    private Payment $ok;

    private Payment $declined;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->requirePayment();
        $this->admin = $this->superAdmin();
        $this->ok = $this->paid($this->offerFrom($this->matchingProvider('Noura Lens'), null, '1000'));
        $this->declined = $this->paid($this->offerFrom($this->matchingProvider('Huda Studio'), null, '300'), '0002');
    }

    private function asAdmin(?User $as = null): static
    {
        return $this->actingAsApi($as ?? $this->admin)->withHeader('Accept-Language', 'en');
    }

    public function test_list_and_filters(): void
    {
        $this->asAdmin()->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->declined->id)
            ->assertJsonPath('meta.total', 2);

        $this->asAdmin()->getJson(self::URL.'?filter[status]=succeeded')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->ok->id)
            ->assertJsonPath('data.0.client.id', $this->client->id)
            ->assertJsonPath('data.0.client.phone', $this->client->phone)
            ->assertJsonPath('data.0.payable.type', 'offer')
            ->assertJsonPath('data.0.booking.id', Booking::query()->sole()->id)
            ->assertJsonPath('data.0.refundable_amount.amount', '1000.00')
            ->assertJsonMissingPath('data.0.raw_response');
        $this->asAdmin()->getJson(self::URL.'?q='.$this->ok->reference)->assertJsonCount(1, 'data');
        $this->asAdmin()->getJson(self::URL.'?q='.Booking::query()->sole()->reference)->assertJsonCount(1, 'data');
        $this->asAdmin()->getJson(self::URL.'?filter[payable_type]=offer&sort=amount')->assertJsonPath('data.0.id', $this->declined->id);

        $this->asAdmin()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::URL."/{$this->ok->id}/refunds", ['amount' => '100', 'reason' => 'Partial goodwill'])->assertCreated();
        $this->asAdmin()->getJson(self::URL.'?filter[refund]=partial')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->ok->id);
        $this->asAdmin()->getJson(self::URL.'?filter[refund]=none')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->declined->id);
        $this->asAdmin()->getJson(self::URL.'?filter[refund]=full')->assertJsonCount(0, 'data');

        $this->asAdmin()->getJson(self::URL.'?filter[status]=nope')->assertStatus(422);
        $this->asAdmin()->getJson(self::URL.'?sort=user_id')->assertStatus(422);
    }

    public function test_detail_has_refunds_and_history(): void
    {
        $this->asAdmin()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::URL."/{$this->ok->id}/refunds", ['amount' => '50', 'reason' => 'First goodwill'])->assertCreated();
        $this->asAdmin()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::URL."/{$this->ok->id}/refunds", ['amount' => '25', 'reason' => 'Second goodwill'])->assertCreated();

        $res = $this->asAdmin()->getJson(self::URL."/{$this->ok->id}")->assertOk()
            ->assertJsonPath('data.reference', $this->ok->reference)
            ->assertJsonPath('data.refund.status', 'partial')
            ->assertJsonPath('data.refunds.0.reason', 'Second goodwill') // newest first
            ->assertJsonPath('data.refunds.1.reason', 'First goodwill')
            ->assertJsonPath('data.refundable_amount.amount', '925.00');
        $events = collect($res->json('data.history'))->pluck('event')->all();
        $this->assertContains('payment_succeeded', $events);
        $this->assertContains('payment_fulfilment_fulfilled', $events);
        $this->assertContains('refund_requested', $events);
        $this->assertContains('refund_refunded', $events);

        $this->asAdmin()->getJson(self::URL.'/999999')->assertStatus(404);
    }

    public function test_refunds_list(): void
    {
        $this->asAdmin()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::URL."/{$this->ok->id}/refunds", ['amount' => '10', 'reason' => 'Goodwill'])->assertCreated();

        $this->asAdmin()->getJson('/api/v1/admin/refunds')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'staff')->assertJsonPath('data.0.payment.reference', $this->ok->reference);
        $this->asAdmin()->getJson('/api/v1/admin/refunds?filter[kind]=acceptance_failed')->assertJsonCount(0, 'data');
        $this->asAdmin()->getJson('/api/v1/admin/refunds?filter[status]=refunded')->assertJsonCount(1, 'data');
    }

    public function test_admin_request_detail_shows_the_booking_payment(): void
    {
        $booking = Booking::query()->sole();
        $this->asAdmin()->getJson("/api/v1/admin/service-requests/{$booking->service_request_id}")->assertOk()
            ->assertJsonPath('data.booking.payment_status', 'paid')
            ->assertJsonPath('data.booking.payment.id', $this->ok->id)
            ->assertJsonPath('data.booking.payment.refund.status', 'none');
    }

    public function test_permissions(): void
    {
        $refunder = User::factory()->admin()->create();
        Role::create(['name' => 'refunder', 'guard_name' => 'api'])->givePermissionTo('refunds.process');
        $refunder->assignRole('refunder');

        $this->asAdmin($this->member(AdminRole::OperationsSupport))->getJson(self::URL)->assertStatus(403);
        $this->asAdmin($refunder)->getJson(self::URL)->assertStatus(403);
        $this->asAdmin($refunder)->getJson('/api/v1/admin/refunds')->assertOk();
        $this->asAdmin($this->member(AdminRole::StoreContentOfficer))->getJson('/api/v1/admin/refunds')->assertStatus(403);
        $this->asAdmin($this->client)->getJson(self::URL)->assertStatus(403);
        $this->asGuest()->getJson(self::URL)->assertStatus(401);
    }
}
