<?php

namespace Tests\Feature\Team;

use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\CancellationRequesterRole;
use App\Models\ActivityEvent;
use App\Models\BookingAssignment;
use App\Models\Provider;
use App\Services\Cancellations\BookingCancellations;
use App\Services\Team\BookingTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsTeam;
use Tests\TestCase;

/**
 * Slice 13 §4 (D230, D232–D234): she sets the team of her booking while it is confirmed or in progress; the
 * assignment is informational and visible to her only (never the client, the request history or the dashboard).
 */
class BookingTeamTest extends TestCase
{
    use BuildsApplications, BuildsTeam, RefreshDatabase;

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

    public function test_set_replaces_the_team_and_answers_with_the_booking(): void
    {
        $reem = $this->teamMember($this->noura, ['name' => 'Reem']);
        $lama = $this->teamMember($this->noura, ['name' => 'Lama', 'title' => 'Coordinator']);
        $dana = $this->teamMember($this->noura, ['name' => 'Dana']);
        $booking = $this->booking($this->noura);

        $this->asProvider($this->noura)->getJson("/api/v1/provider/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.team', [])
            ->assertJsonPath('data.actions.can_assign_team', true);

        $this->setTeam($this->noura, $booking, [$reem->id, $lama->id])->assertOk()
            ->assertJsonCount(2, 'data.team')
            ->assertJsonPath('data.team.0.id', $reem->id)
            ->assertJsonPath('data.team.1.name', 'Lama')
            ->assertJsonPath('data.team.1.title', 'Coordinator')
            ->assertJsonPath('data.team.1.is_active', true)
            ->assertJsonPath('data.team.1.photo', null);

        $this->setTeam($this->noura, $booking, [$lama->id, $dana->id])->assertOk()->assertJsonCount(2, 'data.team');
        $this->assertEqualsCanonicalizing([$lama->id, $dana->id], BookingAssignment::query()->pluck('team_member_id')->all());
        $this->assertSame($this->noura->user_id, BookingAssignment::query()->where('team_member_id', $dana->id)->value('assigned_by'));

        $this->setTeam($this->noura, $booking, [])->assertOk()->assertJsonPath('data.team', []);
        $this->assertSame(0, BookingAssignment::query()->count());
    }

    public function test_a_repeat_with_the_same_body_changes_nothing(): void
    {
        $reem = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);

        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk()->assertJsonCount(1, 'data.team');

        $this->assertSame(1, BookingAssignment::query()->count());
        $this->assertSame(1, ActivityEvent::query()->where('event', 'team_member_assigned')->count());
    }

    public function test_history_is_on_the_booking_and_provider_only(): void
    {
        $reem = $this->teamMember($this->noura, ['name' => 'Reem']);
        $booking = $this->booking($this->noura);

        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();
        $this->setTeam($this->noura, $booking, [])->assertOk();

        $events = ActivityEvent::query()->whereIn('event', ['team_member_assigned', 'team_member_unassigned'])->orderBy('id')->get();
        $this->assertSame(['team_member_assigned', 'team_member_unassigned'], $events->pluck('event')->all());
        foreach ($events as $event) {
            $this->assertSame($booking->getMorphClass(), $event->subject_type);
            $this->assertSame($booking->id, $event->subject_id);
            $this->assertSame('provider', $event->visibility->value);
            $this->assertEquals(['team_member_id' => $reem->id, 'name' => 'Reem'], $event->meta);
        }
    }

    public function test_foreign_or_unknown_members_are_refused(): void
    {
        $other = $this->teamMember($this->huda);
        $mine = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);

        $this->setTeam($this->noura, $booking, [$mine->id, $other->id])->assertUnprocessable()
            ->assertJsonValidationErrors(['member_ids.1']);
        $this->setTeam($this->noura, $booking, [999999])->assertUnprocessable()->assertJsonValidationErrors(['member_ids.0']);
        $this->assertSame(0, BookingAssignment::query()->count()); // all or nothing
    }

    public function test_body_validation(): void
    {
        $mine = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);

        $this->asProvider($this->noura)->putJson("/api/v1/provider/bookings/{$booking->id}/team", [])->assertUnprocessable()
            ->assertJsonValidationErrors(['member_ids']);
        $this->setTeam($this->noura, $booking, [$mine->id, $mine->id])->assertUnprocessable();
        $this->setTeam($this->noura, $booking, range(1, 51))->assertUnprocessable()->assertJsonValidationErrors(['member_ids']);
    }

    public function test_a_disabled_member_cannot_be_newly_assigned_but_may_stay(): void
    {
        $reem = $this->teamMember($this->noura);
        $dana = $this->disabledMember($this->noura, ['name' => 'Dana']);
        $booking = $this->booking($this->noura);

        $this->setTeam($this->noura, $booking, [$reem->id, $dana->id])->assertUnprocessable()
            ->assertJsonValidationErrors(['member_ids.1'])
            ->assertJsonPath('errors', fn ($errors) => str_contains($errors['member_ids.1'][0], 'disabled'));

        // Assigned while active, disabled later: kept on the booking and marked (D233).
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();
        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$reem->id}", ['status' => 'disabled'])->assertOk();
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk()->assertJsonPath('data.team.0.is_active', false);
        $this->setTeam($this->noura, $booking, [])->assertOk(); // may be unassigned
        $this->setTeam($this->noura, $booking, [$reem->id])->assertUnprocessable(); // not newly assigned
    }

    public function test_in_progress_is_editable_completed_and_cancelled_are_read_only(): void
    {
        $reem = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();

        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();
        $this->setTeam($this->noura, $booking, [])->assertOk();
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();

        $this->completeBooking($this->noura, $booking)->assertOk()
            ->assertJsonPath('data.actions.can_assign_team', false)
            ->assertJsonCount(1, 'data.team'); // last team kept (D232)
        $this->setTeam($this->noura, $booking, [])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->assertSame(1, BookingAssignment::query()->count());
    }

    public function test_a_cancelled_booking_is_read_only(): void
    {
        $reem = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();

        $c = app(BookingCancellations::class)->request($this->client, CancellationRequesterRole::Client, $booking, CancellationReason::Other, 'Plans changed');
        app(BookingCancellations::class)->decide($this->staff(AdminRole::OperationsSupport), $c, true, 'Agreed', null);
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);

        $this->setTeam($this->noura, $booking, [])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_another_providers_booking_is_a_404(): void
    {
        $hers = $this->teamMember($this->huda);
        $booking = $this->booking($this->noura);

        $this->setTeam($this->huda, $booking, [$hers->id])->assertNotFound();
        $this->assertSame(0, BookingAssignment::query()->count());
    }

    public function test_the_service_rejects_a_disabled_new_member_directly(): void
    {
        $dana = $this->disabledMember($this->noura);
        $booking = $this->booking($this->noura);

        $this->expectException(ValidationException::class);
        app(BookingTeam::class)->set($this->noura->user, $booking, [$dana->id]);
    }

    public function test_the_team_never_reaches_the_client_or_the_dashboard(): void
    {
        $reem = $this->teamMember($this->noura, ['name' => 'Reem']);
        $booking = $this->booking($this->noura);
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();

        $client = $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertOk();
        $this->assertArrayNotHasKey('team', $client->json('data'));
        $this->assertArrayNotHasKey('can_assign_team', $client->json('data.actions'));
        $this->assertStringNotContainsString('Reem', $client->getContent());

        $request = $this->asClient()->getJson("/api/v1/client/service-requests/{$booking->service_request_id}")->assertOk();
        $this->assertStringNotContainsString('team_member', $request->getContent());

        $admin = $this->actingAsApi($this->staff(AdminRole::OperationsSupport))
            ->getJson("/api/v1/admin/service-requests/{$booking->service_request_id}")->assertOk();
        $this->assertStringNotContainsString('team_member', $admin->getContent());
        $this->assertStringNotContainsString('Reem', $admin->getContent());
    }

    public function test_list_and_calendar_carry_the_team(): void
    {
        $reem = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);
        $this->setTeam($this->noura, $booking, [$reem->id])->assertOk();

        $this->asProvider($this->noura)->getJson('/api/v1/provider/bookings')->assertOk()
            ->assertJsonPath('data.0.team.0.id', $reem->id);
        $this->asProvider($this->noura)->getJson('/api/v1/provider/calendar?from=2026-12-01&to=2026-12-10')->assertOk()
            ->assertJsonPath('data.bookings.0.team.0.id', $reem->id);
        $this->asProvider($this->noura)->getJson(self::TEAM."/{$reem->id}")->assertJsonPath('data.active_assignments_count', 1);
    }
}
