<?php

namespace Tests\Concerns;

use App\Models\AvailabilityBlock;
use App\Models\Provider;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Slice 5a helpers on top of BuildsServiceRequests. Tests freeze time at 2026-12-01 12:00 Riyadh (09:00Z).
 */
trait BuildsAvailability
{
    use BuildsServiceRequests;

    protected const NOW = '2026-12-01T09:00:00Z';

    protected const BLOCKS = '/api/v1/provider/availability-blocks';

    protected function freezeNow(): void
    {
        $this->travelTo(Carbon::parse(self::NOW));
    }

    protected function asProvider(Provider $provider): static
    {
        return $this->actingAsApi($provider->user)->withHeader('Accept-Language', 'en');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function block(Provider $provider, array $body): TestResponse
    {
        return $this->asProvider($provider)->postJson(self::BLOCKS, $body);
    }

    /**
     * Stores an active block directly (UTC ISO strings).
     */
    protected function storeBlock(Provider $provider, string $startsAt, string $endsAt): AvailabilityBlock
    {
        return AvailabilityBlock::query()->create([
            'provider_id' => $provider->id,
            'starts_at' => Carbon::parse($startsAt)->utc(),
            'ends_at' => Carbon::parse($endsAt)->utc(),
            'created_by' => $provider->user_id,
        ]);
    }

    /**
     * A direct request body for a Riyadh date and time.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function directAt(Provider $provider, string $date, string $time, int $minutes = 60, array $overrides = []): array
    {
        return $this->directBody($provider, ['event_date' => $date, 'start_time' => $time, 'duration_minutes' => $minutes, ...$overrides]);
    }
}
