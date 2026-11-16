<?php

namespace Tests\Feature\Bookings;

use App\Enums\ProviderStatus;
use App\Models\ContentReport;
use App\Models\Provider;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 6 §5 (D129): one review per completed booking, published at once, never edited; one provider reply;
 * provider reports; public list on the provider profile; rating cache.
 */
class ReviewTest extends TestCase
{
    use BuildsBookings, RefreshDatabase;

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

    private function review(int $bookingId, array $body = [], ?User $as = null): TestResponse
    {
        return $this->asClient($as)->postJson("/api/v1/client/bookings/{$bookingId}/review",
            [...['rating' => 5, 'comment' => 'Wonderful photos', 'tags' => ['punctuality', 'service_quality']], ...$body]);
    }

    public function test_client_reviews_a_completed_booking(): void
    {
        $booking = $this->completedBooking($this->noura);

        $this->review($booking->id)->assertCreated()
            ->assertJsonPath('data.review.rating', 5)
            ->assertJsonPath('data.review.comment', 'Wonderful photos')
            ->assertJsonPath('data.review.tags.0.code', 'punctuality')
            ->assertJsonPath('data.review.tags.0.label', 'Punctuality')
            ->assertJsonPath('data.actions.can_review', false);

        $this->noura->refresh();
        $this->assertSame(1, $this->noura->rating_count);
        $this->assertSame('5.00', (string) $this->noura->rating_avg);
    }

    public function test_only_completed_bookings_can_be_reviewed(): void
    {
        $booking = $this->booking($this->noura);
        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertJsonPath('data.actions.can_review', false);
        $this->review($booking->id)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();
        $this->review($booking->id)->assertStatus(409);
        $this->assertSame(0, Review::query()->count());
    }

    public function test_one_review_per_booking(): void
    {
        $booking = $this->completedBooking($this->noura);
        $this->review($booking->id)->assertCreated();

        $this->review($booking->id, ['rating' => 1])->assertStatus(409)->assertJsonPath('code', 'ALREADY_REVIEWED');
        $this->assertSame(5, Review::query()->sole()->rating); // never edited
    }

    public function test_another_client_cannot_review(): void
    {
        $booking = $this->completedBooking($this->noura);
        $this->review($booking->id, [], User::factory()->client()->create())->assertNotFound();
    }

    public function test_rating_validation(): void
    {
        $booking = $this->completedBooking($this->noura);

        foreach ([0, 6, null, 'five', 4.5] as $rating) {
            $this->review($booking->id, ['rating' => $rating])->assertUnprocessable()->assertJsonValidationErrors('rating', 'errors');
        }
        $this->review($booking->id, ['comment' => str_repeat('a', 1001)])->assertUnprocessable();
        $this->assertSame(0, Review::query()->count());
    }

    public function test_tags_validation(): void
    {
        $booking = $this->completedBooking($this->noura);

        $this->review($booking->id, ['tags' => ['nice']])->assertUnprocessable()->assertJsonValidationErrors('tags.0', 'errors');
        $this->review($booking->id, ['tags' => ['friendly', 'friendly']])->assertUnprocessable();
        $this->review($booking->id, ['tags' => 'friendly'])->assertUnprocessable();
        $this->review($booking->id, ['tags' => [], 'comment' => null])->assertCreated()->assertJsonPath('data.review.tags', []);

        $this->getJson('/api/v1/shared/review-tags')->assertOk()
            ->assertJsonPath('data.*.code', ['punctuality', 'service_quality', 'friendly', 'matches_quote', 'clean_organized']);
    }

    public function test_provider_replies_once(): void
    {
        $booking = $this->completedBooking($this->noura);
        $this->review($booking->id)->assertCreated();
        $review = Review::query()->sole();

        $this->asProvider($this->huda)->postJson("/api/v1/provider/reviews/{$review->id}/reply", ['reply' => 'Hi'])->assertNotFound();
        $this->asProvider($this->noura)->postJson("/api/v1/provider/reviews/{$review->id}/reply", ['reply' => ''])->assertUnprocessable();
        $this->asProvider($this->noura)->postJson("/api/v1/provider/reviews/{$review->id}/reply", ['reply' => str_repeat('a', 501)])->assertUnprocessable();
        $this->asProvider($this->noura)->postJson("/api/v1/provider/reviews/{$review->id}/reply", ['reply' => 'Thank you, Sara!'])->assertOk()
            ->assertJsonPath('data.provider_reply', 'Thank you, Sara!');
        $this->asProvider($this->noura)->postJson("/api/v1/provider/reviews/{$review->id}/reply", ['reply' => 'Edited'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->assertSame('Thank you, Sara!', $review->refresh()->provider_reply);
        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertJsonPath('data.review.provider_reply', 'Thank you, Sara!');
    }

    public function test_provider_reports_a_review_once(): void
    {
        $booking = $this->completedBooking($this->noura);
        $this->review($booking->id)->assertCreated();
        $review = Review::query()->sole();
        $url = "/api/v1/provider/reviews/{$review->id}/report";

        $this->asProvider($this->noura)->postJson($url, ['reason_code' => 'bad'])->assertUnprocessable();
        $this->asProvider($this->noura)->postJson($url, ['reason_code' => 'other', 'details' => str_repeat('a', 301)])->assertUnprocessable();
        $this->asProvider($this->huda)->postJson($url, ['reason_code' => 'spam'])->assertNotFound();
        $this->asProvider($this->noura)->postJson($url, ['reason_code' => 'false_info', 'details' => 'Never happened'])->assertCreated();
        $this->asProvider($this->noura)->postJson($url, ['reason_code' => 'spam'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_REPORTED');

        $report = ContentReport::query()->sole();
        $this->assertSame('false_info', $report->reason_code->value);
        $this->assertSame('open', $report->status->value);
        // Still published (moderation is Slice 17); the client is not told.
        $this->getJson("/api/v1/shared/providers/{$this->noura->id}/reviews")->assertJsonCount(1, 'data');
        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertJsonMissingPath('data.review.reported');
        $this->asProvider($this->noura)->getJson('/api/v1/provider/review-report-reasons')->assertOk()
            ->assertJsonPath('data.*.code', ['abusive', 'spam', 'false_info', 'privacy', 'other']);
    }

    public function test_provider_review_list_with_summary(): void
    {
        $first = $this->completedBooking($this->noura);
        $this->review($first->id, ['rating' => 5])->assertCreated();
        $this->freezeNow();
        $second = $this->completedBooking($this->noura);
        $this->review($second->id, ['rating' => 3, 'tags' => []])->assertCreated();
        $this->asProvider($this->noura)->postJson('/api/v1/provider/reviews/'.Review::query()->where('rating', 3)->value('id').'/report', ['reason_code' => 'spam']);

        $this->asProvider($this->noura)->getJson('/api/v1/provider/reviews')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary.count', 2)
            ->assertJsonPath('meta.summary.average', 4)
            ->assertJsonPath('meta.summary.breakdown.5', 1)
            ->assertJsonPath('meta.summary.breakdown.3', 1)
            ->assertJsonPath('meta.summary.breakdown.1', 0)
            ->assertJsonPath('data.0.client.first_name', 'Sara')
            ->assertJsonPath('data.0.reported', fn ($v) => is_bool($v));
        $this->asProvider($this->huda)->getJson('/api/v1/provider/reviews')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.summary.average', null);
        $this->assertSame('4.00', (string) $this->noura->refresh()->rating_avg);
    }

    public function test_public_reviews_on_the_provider_profile(): void
    {
        $booking = $this->completedBooking($this->noura);
        $this->review($booking->id)->assertCreated();

        $res = $this->getJson("/api/v1/shared/providers/{$this->noura->id}/reviews")->assertOk() // guest, no token
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rating', 5)
            ->assertJsonPath('data.0.client.first_name', 'Sara')
            ->assertJsonPath('meta.total', 1);
        $this->assertArrayNotHasKey('booking', $res->json('data.0'));
        $this->assertStringNotContainsString('Al Harbi', $res->getContent());
        $this->getJson("/api/v1/shared/providers/{$this->noura->id}")->assertJsonPath('data.rating.count', 1);

        Review::query()->update(['status' => 'hidden']);
        $this->getJson("/api/v1/shared/providers/{$this->noura->id}/reviews")->assertJsonCount(0, 'data');

        $this->noura->update(['status' => ProviderStatus::Suspended]);
        $this->getJson("/api/v1/shared/providers/{$this->noura->id}/reviews")->assertNotFound();
    }
}
