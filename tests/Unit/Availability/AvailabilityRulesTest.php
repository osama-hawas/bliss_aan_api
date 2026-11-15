<?php

namespace Tests\Unit\Availability;

use App\Models\AvailabilityBlock;
use App\Services\Availability\ProviderAvailability;
use App\Support\RiyadhCalendar;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * §15 unit: Riyadh day ↔ UTC, block dates, whole days and the full-day coverage rule (D92). No database.
 */
class AvailabilityRulesTest extends TestCase
{
    private function block(string $start, string $end): AvailabilityBlock
    {
        return new AvailabilityBlock(['starts_at' => Carbon::parse($start), 'ends_at' => Carbon::parse($end)]);
    }

    private function covers(string $date, AvailabilityBlock ...$blocks): bool
    {
        return app(ProviderAvailability::class)->coversDay(collect($blocks)->sortBy(fn ($b) => $b->starts_at->getTimestamp())->values(), $date);
    }

    public function test_riyadh_day_is_the_utc_interval_from_21_00_the_day_before(): void
    {
        $this->assertSame('2026-12-04T21:00:00Z', RiyadhCalendar::dayStart('2026-12-05')->toIso8601ZuluString());
        $this->assertSame('2026-12-05T21:00:00Z', RiyadhCalendar::dayEnd('2026-12-05')->toIso8601ZuluString());
        $this->assertSame('2026-12-31T21:00:00Z', RiyadhCalendar::dayStart('2027-01-01')->toIso8601ZuluString());
        $this->assertSame('2026-12-30T21:00:00Z', RiyadhCalendar::dayStart('2026-12-31')->toIso8601ZuluString());
    }

    public function test_today_is_the_riyadh_date(): void
    {
        $this->travelTo(Carbon::parse('2026-12-04T20:59:00Z'));
        $this->assertSame('2026-12-04', RiyadhCalendar::today());
        $this->travelTo(Carbon::parse('2026-12-04T21:00:00Z'));
        $this->assertSame('2026-12-05', RiyadhCalendar::today());
        $this->travelTo(Carbon::parse('2026-12-04T22:00:00Z'));
        $this->assertSame('2026-12-05', RiyadhCalendar::today());
    }

    public function test_block_dates_and_whole_days(): void
    {
        $day = $this->block('2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $this->assertSame('2026-12-05', RiyadhCalendar::dateOf($day->starts_at));
        $this->assertSame('2026-12-05', RiyadhCalendar::lastDateBefore($day->ends_at));
        $this->assertTrue($day->wholeDays());

        $range = $this->block('2026-12-06T10:00:00Z', '2026-12-06T14:00:00Z');
        $this->assertSame('2026-12-06', RiyadhCalendar::dateOf($range->starts_at));
        $this->assertSame('2026-12-06', RiyadhCalendar::lastDateBefore($range->ends_at));
        $this->assertFalse($range->wholeDays());

        $crossing = $this->block('2026-12-04T15:00:00Z', '2026-12-06T03:00:00Z'); // 18:00 on 12-04 → 06:00 on 12-06
        $this->assertSame('2026-12-04', RiyadhCalendar::dateOf($crossing->starts_at));
        $this->assertSame('2026-12-06', RiyadhCalendar::lastDateBefore($crossing->ends_at));
    }

    /**
     * The §7.1 table for 2026-12-05 (Riyadh; UTC = Riyadh − 3 h).
     */
    public function test_full_day_coverage_rule(): void
    {
        $this->assertTrue($this->covers('2026-12-05', $this->block('2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z')));
        $this->assertFalse($this->covers('2026-12-05', $this->block('2026-12-05T10:00:00Z', '2026-12-05T14:00:00Z')));
        $this->assertTrue($this->covers('2026-12-05',
            $this->block('2026-12-04T21:00:00Z', '2026-12-05T09:00:00Z'),
            $this->block('2026-12-05T09:00:00Z', '2026-12-05T21:00:00Z')));
        $this->assertFalse($this->covers('2026-12-05',
            $this->block('2026-12-04T21:00:00Z', '2026-12-05T09:00:00Z'),
            $this->block('2026-12-05T09:01:00Z', '2026-12-05T21:00:00Z')));
        $this->assertFalse($this->covers('2026-12-05', $this->block('2026-12-04T21:00:00Z', '2026-12-05T20:59:00Z')));

        $multi = $this->block('2026-12-04T15:00:00Z', '2026-12-06T03:00:00Z');
        $this->assertFalse($this->covers('2026-12-04', $multi));
        $this->assertTrue($this->covers('2026-12-05', $multi));
        $this->assertFalse($this->covers('2026-12-06', $multi));

        $fromPrevious = $this->block('2026-12-04T17:00:00Z', '2026-12-05T21:00:00Z'); // 20:00 on 12-04 → 24:00 on 12-05
        $this->assertFalse($this->covers('2026-12-04', $fromPrevious));
        $this->assertTrue($this->covers('2026-12-05', $fromPrevious));

        $this->assertFalse($this->covers('2026-12-05'));
    }

    public function test_overlapping_pieces_are_joined_without_relying_on_d85(): void
    {
        $this->assertTrue($this->covers('2026-12-05',
            $this->block('2026-12-04T21:00:00Z', '2026-12-05T12:00:00Z'),
            $this->block('2026-12-05T06:00:00Z', '2026-12-05T08:00:00Z'),
            $this->block('2026-12-05T10:00:00Z', '2026-12-05T21:00:00Z')));
    }
}
