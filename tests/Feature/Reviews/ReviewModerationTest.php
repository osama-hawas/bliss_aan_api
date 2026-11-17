<?php

namespace Tests\Feature\Reviews;

use App\Enums\ReportReason;
use App\Enums\ReviewStatus;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §8 (D261): hide / restore with a reason, rating recomputed, the three readers.
 */
class ReviewModerationTest extends TestCase
{
    use BuildsBookings, BuildsNotifications, BuildsOperations, RefreshDatabase;

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

    private function reviewed(int $rating): Review
    {
        $booking = $this->completedBooking($this->noura);

        return app(ReviewService::class)->submit($this->client, $booking, $rating, 'Comment '.$rating, []);
    }

    public function test_hide_and_restore(): void
    {
        $good = $this->reviewed(5);
        $bad = $this->reviewed(1);
        $this->assertSame(2, $this->noura->refresh()->rating_count);

        $this->asStaff($this->staff)->postJson("/api/v1/admin/reviews/{$bad->id}/hide", ['reason' => 'Abusive language', 'internal_note' => 'Reported twice'])
            ->assertOk()
            ->assertJsonPath('data.status', 'hidden')
            ->assertJsonPath('data.hidden_reason', 'Abusive language')
            ->assertJsonPath('data.hidden_by.id', $this->staff->id)
            ->assertJsonPath('data.moderation_note', 'Reported twice');
        $this->noura->refresh();
        $this->assertSame(1, $this->noura->rating_count);
        $this->assertEquals(5.0, (float) $this->noura->rating_avg);
        $this->assertSame('review.hidden', AuditLog::query()->latest('id')->value('action'));

        // Readers (D261): public and provider lists drop it; the client sees it marked, without the reason.
        $this->getJson("/api/v1/shared/providers/{$this->noura->id}/reviews")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $good->id);
        $this->asProvider($this->noura)->getJson('/api/v1/provider/reviews')->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.count', 1);
        $this->asClient()->getJson("/api/v1/client/bookings/{$bad->booking_id}")->assertOk()
            ->assertJsonPath('data.review.status', 'hidden')
            ->assertJsonPath('data.review.status_label', 'Hidden by Bliss aan')
            ->assertJsonMissing(['hidden_reason' => 'Abusive language'])
            ->assertJsonPath('data.actions.can_review', false);
        $this->assertSame([], array_values(array_filter($this->events($this->client), fn ($e) => str_contains($e, 'review_h'))));

        $this->asStaff($this->staff)->postJson("/api/v1/admin/reviews/{$bad->id}/hide", ['reason' => 'Again'])->assertStatus(409);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/reviews/{$bad->id}/restore", ['reason' => 'Appeal accepted'])->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.hidden_reason', null);
        $this->assertSame(2, $this->noura->refresh()->rating_count);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/reviews/{$bad->id}/restore", ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_list_filters_and_permissions(): void
    {
        $r = $this->reviewed(2);
        $this->reviewed(5);
        app(ReviewService::class)->report($this->noura->user, $r, ReportReason::Abusive, null);

        $this->asStaff($this->staff)->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonCount(2, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/reviews?filter[reported]=1')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.open_reports_count', 1)->assertJsonPath('data.0.client.name', 'Sara Al Harbi');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/reviews?filter[rating]=5')->assertJsonCount(1, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/reviews?filter[status]=hidden')->assertJsonCount(0, 'data');

        $this->asStaff($this->staffWith(['bookings.view']))->getJson('/api/v1/admin/reviews')->assertForbidden();
        $this->asStaff($this->staff)->postJson("/api/v1/admin/reviews/{$r->id}/hide", ['reason' => ''])->assertStatus(422);
        $this->assertSame(ReviewStatus::Published, $r->refresh()->status);
    }
}
