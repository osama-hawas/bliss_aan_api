<?php

namespace App\Services\Availability;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\ProviderStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Exceptions\ApiException;
use App\Models\AvailabilityBlock;
use App\Models\Provider;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Support\RiyadhCalendar;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Her availability blocks and calendar (Slice 5a §5). Create and delete run under the provider row lock (D90, D95)
 * inside a deadlock-retried transaction (D98); the status is re-checked under that lock (D96).
 */
class AvailabilityBlocks
{
    /** Provider statuses that may read the calendar and delete blocks (D96). */
    private const VIEW_STATUSES = [ProviderStatus::Active, ProviderStatus::Suspended];

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly ProviderAvailability $availability,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * Her provider when its status is allowed, else 404 (no provider) / 409 INVALID_STATE.
     *
     * @param  list<ProviderStatus>  $allowed
     */
    public function providerOf(User $user, array $allowed = self::VIEW_STATUSES): Provider
    {
        $provider = $user->provider ?? throw new ApiException(ErrorCode::NotFound);
        if (! in_array($provider->status, $allowed, true)) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $provider;
    }

    /**
     * Active blocks and open requests overlapping the Riyadh dates [from, to] (§5.3). Suspended: blocks only (D99).
     *
     * @return array{blocks: Collection<int, AvailabilityBlock>, requests: Collection<int, ServiceRequestRecipient>}
     */
    public function calendar(Provider $provider, string $from, string $to): array
    {
        $start = RiyadhCalendar::dayStart($from);
        $end = RiyadhCalendar::dayEnd($to);

        $blocks = AvailabilityBlock::query()
            ->where('provider_id', $provider->id)
            ->overlapping($start, $end)
            ->orderBy('starts_at')->orderBy('id')
            ->get();

        $requests = $provider->status === ProviderStatus::Active
            ? $this->openRequests($provider, $start, $end)
            : new Collection;

        return ['blocks' => $blocks, 'requests' => $requests];
    }

    /**
     * Creates a block over [startsAt, endsAt) (validated input, UTC). Allowed over requests (D89): they are returned
     * as the conflicting ones and never changed.
     *
     * @return array{0: AvailabilityBlock, 1: Collection<int, ServiceRequestRecipient>}
     */
    public function create(User $user, Carbon $startsAt, Carbon $endsAt, ?string $reason): array
    {
        $provider = $this->providerOf($user, [ProviderStatus::Active]);

        return $this->retry->run(fn () => DB::transaction(function () use ($user, $provider, $startsAt, $endsAt, $reason) {
            $locked = $this->lock($provider);
            if ($locked->status !== ProviderStatus::Active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if ($this->availability->isBlocked($provider->id, $startsAt, $endsAt)) {
                throw new ApiException(ErrorCode::BlockOverlap);
            }

            $block = AvailabilityBlock::query()->create([
                'provider_id' => $provider->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason,
                'created_by' => $user->id,
            ]);
            $this->record($locked, $block, 'availability_blocked', $user);

            return [$block, $this->openRequests($locked, $startsAt, $endsAt)];
        }), 'availability_block.create', [
            'user_id' => $user->id, 'provider_id' => $provider->id,
            'starts_at' => $startsAt->toIso8601ZuluString(), 'ends_at' => $endsAt->toIso8601ZuluString(),
        ]);
    }

    /**
     * Soft-deletes one of her active blocks (D94). Another provider's, unknown or already deleted → 404.
     */
    public function delete(User $user, int $blockId): void
    {
        $provider = $this->providerOf($user);

        $this->retry->run(fn () => DB::transaction(function () use ($user, $provider, $blockId) {
            $locked = $this->lock($provider);
            if (! in_array($locked->status, self::VIEW_STATUSES, true)) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $block = AvailabilityBlock::query()->where('provider_id', $provider->id)->find($blockId)
                ?? throw new ApiException(ErrorCode::NotFound);
            if ($user->cannot('delete', $block)) {
                throw new ApiException(ErrorCode::NotFound);
            }

            $block->forceFill(['deleted_by' => $user->id])->save();
            $block->delete();
            $this->record($locked, $block, 'availability_unblocked', $user);
        }), 'availability_block.delete', ['user_id' => $user->id, 'provider_id' => $provider->id, 'block_id' => $blockId]);
    }

    /**
     * Requests she still takes part in (`new` / `offer_sent`) on non-final requests, overlapping [from, to).
     *
     * @return Collection<int, ServiceRequestRecipient>
     */
    private function openRequests(Provider $provider, DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        $rows = ServiceRequestRecipient::query()
            ->select('service_request_recipients.*')
            ->join('service_requests', 'service_requests.id', '=', 'service_request_recipients.service_request_id')
            ->where('service_request_recipients.provider_id', $provider->id)
            ->whereIn('service_request_recipients.status', [RecipientStatus::New, RecipientStatus::OfferSent])
            ->whereIn('service_requests.status', [ServiceRequestStatus::AwaitingOffers, ServiceRequestStatus::OffersReceived])
            ->where(fn (Builder $q) => $q->where('service_requests.starts_at', '<', $to)->where('service_requests.ends_at', '>', $from))
            ->with(['serviceRequest.service', 'serviceRequest.city'])
            ->orderBy('service_requests.starts_at')
            ->orderBy('service_requests.id')
            ->get();

        $this->availability->fillConflicts($rows);

        return $rows;
    }

    private function lock(Provider $provider): Provider
    {
        return Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
    }

    private function record(Provider $provider, AvailabilityBlock $block, string $event, User $user): void
    {
        $this->activity->record($provider, $event, actor: $user, visibility: ActivityVisibility::Internal, meta: [
            'block_id' => $block->id,
            'starts_at' => $block->starts_at->toIso8601ZuluString(),
            'ends_at' => $block->ends_at->toIso8601ZuluString(),
        ]);
    }
}
