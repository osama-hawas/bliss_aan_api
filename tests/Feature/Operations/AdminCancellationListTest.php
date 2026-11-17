<?php

namespace Tests\Feature\Operations;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §5.3: the cancellation list gains `q`, the `staff` role and the payment/refund summary.
 */
class AdminCancellationListTest extends TestCase
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

    public function test_q_staff_role_and_payment_fields(): void
    {
        $a = $this->booking($this->noura);
        $this->clientCancel($a)->assertCreated();
        $b = $this->booking($this->matchingProvider('Huda Studio'));
        $this->asStaff($this->staff)->postJson("/api/v1/admin/bookings/{$b->id}/cancel", ['reason' => 'Provider unavailable'])->assertOk();

        $this->asStaff($this->staff)->getJson('/api/v1/admin/cancellation-requests')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.payment', null)->assertJsonPath('data.0.refunds', []);
        $this->asStaff($this->staff)->getJson('/api/v1/admin/cancellation-requests?filter[requester_role]=staff')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking.id', $b->id)->assertJsonPath('data.0.status', 'accepted');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/cancellation-requests?q='.$a->reference)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking.id', $a->id);
        $this->asStaff($this->staff)->getJson('/api/v1/admin/cancellation-requests?q=Huda')->assertJsonCount(1, 'data');
    }
}
