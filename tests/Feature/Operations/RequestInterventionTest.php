<?php

namespace Tests\Feature\Operations;

use App\Enums\OfferStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use App\Services\Offers\OfferSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §4 (D256, D257): staff cancel an open request and add internal notes.
 */
class RequestInterventionTest extends TestCase
{
    use BuildsBookings, BuildsNotifications, BuildsOperations, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
        $this->staff = $this->ops();
    }

    public function test_staff_cancel_an_open_request(): void
    {
        $request = $this->created($this->openBody());
        app(OfferSender::class)->send($this->noura, $request, (string) Str::uuid(), [
            'title' => 'Photography', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
        ]);

        $this->asStaff($this->staff)->postJson("/api/v1/admin/service-requests/{$request->id}/cancel",
            ['reason' => 'Duplicate request, confirmed with the client.', 'internal_note' => 'Ticket TK-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.actions.can_cancel', false)
            ->assertJsonPath('data.staff_notes.0.note', 'Ticket TK-1');

        $request->refresh();
        $this->assertSame(ServiceRequestStatus::Cancelled, $request->status);
        $this->assertSame('Duplicate request, confirmed with the client.', $request->cancel_reason);
        $this->assertSame(0, ServiceRequestRecipient::query()->where('service_request_id', $request->id)->whereIn('status', [RecipientStatus::New, RecipientStatus::OfferSent])->count());
        $this->assertSame(OfferStatus::Closed, Offer::query()->where('service_request_id', $request->id)->sole()->status);
        $this->assertSame('service_request.cancelled_by_staff', AuditLog::query()->latest('id')->value('action'));
        $this->assertContains('request_cancelled_by_staff', $this->events($this->client));
        // Providers get the staff wording too (never "<client> cancelled", E3).
        $this->assertContains('request_cancelled_by_staff', $this->events($this->noura->user_id));
        $this->assertContains('request_cancelled_by_staff', $this->events($this->huda->user_id));
        $this->assertNotContains('request_cancelled', $this->events($this->noura->user_id));
    }

    public function test_a_booked_or_cancelled_request_is_refused(): void
    {
        $booking = $this->booking($this->noura);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/service-requests/{$booking->service_request_id}/cancel", ['reason' => 'Not allowed here'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_note_is_internal(): void
    {
        $request = $this->created($this->openBody());

        $this->asStaff($this->staff)->postJson("/api/v1/admin/service-requests/{$request->id}/notes", ['note' => 'Watch this one.'])
            ->assertCreated()->assertJsonPath('data.staff_notes.0.note', 'Watch this one.')
            ->assertJsonPath('data.actions.can_add_note', true);
        $this->asClient()->getJson("/api/v1/client/service-requests/{$request->id}")->assertOk()->assertJsonMissing(['event' => 'staff_note']);
    }

    public function test_permissions(): void
    {
        $request = $this->created($this->openBody());
        $viewer = $this->staffWith(['bookings.view']);

        $this->asStaff($viewer)->postJson("/api/v1/admin/service-requests/{$request->id}/cancel", ['reason' => 'No permission'])->assertForbidden();
        $this->asStaff($viewer)->postJson("/api/v1/admin/service-requests/{$request->id}/notes", ['note' => 'x'])->assertForbidden();
        $this->asStaff($viewer)->getJson("/api/v1/admin/service-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.actions.can_cancel', false);
        $this->assertSame(ServiceRequestStatus::AwaitingOffers, $request->refresh()->status);
    }
}
