<?php

namespace Tests\Feature\Operations;

use App\Enums\TicketRelatedType;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §5.1: `GET admin/bookings` and `GET admin/bookings/{id}` (`bookings.view`).
 */
class AdminBookingsTest extends TestCase
{
    use BuildsBookings, BuildsOperations, RefreshDatabase;

    private Provider $noura;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->staff = $this->ops();
    }

    public function test_list_with_filters(): void
    {
        $a = $this->booking($this->noura);
        $b = $this->booking($this->matchingProvider('Huda Studio'));
        $this->clientCancel($b)->assertCreated();
        $this->ticket($this->client, TicketRelatedType::Booking->value, $a->id);

        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'reference', 'status', 'status_label', 'payment_status', 'title', 'total',
                'service', 'client' => ['id', 'name', 'phone'], 'provider', 'city', 'starts_at', 'request', 'has_pending_cancellation',
                'open_tickets_count']], 'meta']);

        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings?filter[has_pending_cancellation]=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.0.has_pending_cancellation', true);
        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings?q='.$a->reference)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.open_tickets_count', 1);
        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings?filter[provider_id]='.$this->noura->id)->assertJsonCount(1, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings?filter[status][]=cancelled')->assertJsonCount(0, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings?filter[from]=2026-12-05&filter[to]=2026-12-05')->assertJsonCount(2, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings?filter[from]=2026-12-06')->assertJsonCount(0, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings?sort=price')->assertStatus(422);
    }

    public function test_detail(): void
    {
        $booking = $this->booking($this->noura);
        $this->ticket($this->client, TicketRelatedType::ServiceRequest->value, $booking->service_request_id);

        $this->asStaff($this->staff)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.address.details', $booking->address['details'] ?? null)
            ->assertJsonPath('data.tickets.0.subject', 'Late arrival')
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.actions.can_cancel', true)
            ->assertJsonPath('data.actions.can_complete', false)
            ->assertJsonPath('data.actions.can_refund', false)
            ->assertJsonStructure(['data' => ['history' => [['event', 'event_label', 'visibility', 'actor', 'note', 'at']], 'staff_notes', 'cancellation_requests', 'review', 'offer']]);
    }

    public function test_access(): void
    {
        $booking = $this->booking($this->noura);

        $this->asStaff($this->staffWith(['support.view']))->getJson('/api/v1/admin/bookings')->assertForbidden();
        $this->asClient()->getJson('/api/v1/admin/bookings')->assertForbidden();
        $this->asStaff($this->staff)->getJson('/api/v1/admin/bookings/999999')->assertNotFound();
    }

    /**
     * Its own test with the headers cleared: `actingAsApi()` keeps the Authorization header for later requests in the
     * same test, so a "guest" call after any signed-in call (staff, or the client inside `booking()`) still carries a token.
     */
    public function test_a_guest_is_refused(): void
    {
        $booking = $this->booking($this->noura); // creates the request through the API as the client (its token stays set)

        // A real guest: no Authorization header and a fresh guard.
        $this->flushHeaders();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/admin/bookings')->assertUnauthorized();
        $this->getJson("/api/v1/admin/bookings/{$booking->id}")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel", ['reason' => 'No token here'])->assertUnauthorized();
    }
}
