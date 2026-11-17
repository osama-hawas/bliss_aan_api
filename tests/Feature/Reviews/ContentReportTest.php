<?php

namespace Tests\Feature\Reviews;

use App\Enums\ContentReportDecision;
use App\Enums\ContentReportStatus;
use App\Enums\ReportReason;
use App\Enums\ReviewStatus;
use App\Models\ContentReport;
use App\Models\Provider;
use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §8 (D262): content report decisions.
 */
class ContentReportTest extends TestCase
{
    use BuildsBookings, BuildsOperations, RefreshDatabase;

    private Provider $noura;

    private User $staff;

    private Review $review;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->staff = $this->ops();
        $this->review = app(ReviewService::class)->submit($this->client, $this->completedBooking($this->noura), 1, 'Bad words', []);
    }

    private function report(?User $by = null): ContentReport
    {
        return app(ReviewService::class)->report($by ?? $this->noura->user, $this->review, ReportReason::Abusive, 'Insults');
    }

    public function test_actioned_hides_the_review_and_closes_every_open_report(): void
    {
        $first = $this->report();
        $second = $this->report(User::factory()->provider()->create());

        $this->asStaff($this->staff)->getJson('/api/v1/admin/content-reports')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.target_type', 'review')->assertJsonPath('data.0.target.rating', 1);

        $this->asStaff($this->staff)->postJson("/api/v1/admin/content-reports/{$first->id}/decide",
            ['decision' => 'actioned', 'reason' => 'Abusive, hidden', 'internal_note' => 'Clear case'])
            ->assertOk()->assertJsonPath('data.status', 'handled')->assertJsonPath('data.decision', 'actioned')
            ->assertJsonPath('data.handled_by.id', $this->staff->id)->assertJsonPath('data.internal_note', 'Clear case')
            ->assertJsonPath('data.target.status', 'hidden');

        $this->assertSame(ReviewStatus::Hidden, $this->review->refresh()->status);
        $this->assertSame(0, $this->noura->refresh()->rating_count);
        $second->refresh();
        $this->assertSame(ContentReportStatus::Handled, $second->status);
        $this->assertSame(ContentReportDecision::Actioned, $second->decision);
        $this->assertNull($second->internal_note);

        $this->asStaff($this->staff)->postJson("/api/v1/admin/content-reports/{$first->id}/decide", ['decision' => 'dismissed', 'reason' => 'Twice'])->assertStatus(409);
    }

    public function test_dismissed_closes_one_report_and_keeps_the_review(): void
    {
        $first = $this->report();
        $second = $this->report(User::factory()->provider()->create());

        $this->asStaff($this->staff)->postJson("/api/v1/admin/content-reports/{$first->id}/decide", ['decision' => 'dismissed', 'reason' => 'Fair criticism'])
            ->assertOk()->assertJsonPath('data.decision', 'dismissed');
        $this->assertSame(ReviewStatus::Published, $this->review->refresh()->status);
        $this->assertSame(ContentReportStatus::Open, $second->refresh()->status);
        $this->asStaff($this->staff)->getJson('/api/v1/admin/content-reports?filter[status]=open')->assertJsonCount(1, 'data');
    }

    public function test_validation_and_permission(): void
    {
        $r = $this->report();
        $this->asStaff($this->staff)->postJson("/api/v1/admin/content-reports/{$r->id}/decide", ['decision' => 'deleted', 'reason' => 'x reason'])->assertStatus(422);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/content-reports/{$r->id}/decide", ['decision' => 'actioned'])->assertStatus(422);
        $this->asStaff($this->staffWith(['support.view']))->postJson("/api/v1/admin/content-reports/{$r->id}/decide", ['decision' => 'actioned', 'reason' => 'x reason'])->assertForbidden();
        $this->assertSame(ContentReportStatus::Open, $r->refresh()->status);
    }
}
