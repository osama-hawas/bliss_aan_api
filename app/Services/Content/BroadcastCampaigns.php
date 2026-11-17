<?php

namespace App\Services\Content;

use App\Enums\Audience;
use App\Enums\BroadcastStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Jobs\SendBroadcastCampaign;
use App\Models\BroadcastCampaign;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Broadcast campaigns (Slice 20, D305). Every move runs under the campaign row lock, so a campaign is sent once.
 * Delivery is `SendBroadcastCampaign` → Slice 8 `Notifier` (D306).
 */
class BroadcastCampaigns
{
    public const SORTS = ['created_at', '-created_at', 'scheduled_at', '-scheduled_at', 'sent_at', '-sent_at'];

    private const FIELDS = ['title', 'body', 'app', 'city_id'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BroadcastAudience $audience,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, audience: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';

        return BroadcastCampaign::query()
            ->with(['city', 'creator', 'sender'])
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('title->ar', 'like', "%{$text}%")->orWhere('title->en', 'like', "%{$text}%")
                ->orWhere('body->ar', 'like', "%{$text}%")->orWhere('body->en', 'like', "%{$text}%")))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['audience'], fn (Builder $q, string $app) => $q->where('app', $app))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): BroadcastCampaign
    {
        return BroadcastCampaign::query()->with(['city', 'creator', 'sender'])->findOrFail($id);
    }

    public function audienceCount(BroadcastCampaign $campaign): int
    {
        return $this->audience->count($campaign);
    }

    /**
     * @param  array{title: array{ar: string, en: string}, body: array{ar: string, en: string}, app: Audience, city_id: ?int}  $data
     */
    public function create(User $admin, array $data): BroadcastCampaign
    {
        return DB::transaction(function () use ($admin, $data) {
            $campaign = BroadcastCampaign::query()->create([...$data, 'status' => BroadcastStatus::Draft, 'created_by' => $admin->id]);
            $this->audit->log('broadcast_created', $admin, $campaign, null, null, ContentVisibility::snapshot($campaign, self::FIELDS));

            return $campaign;
        });
    }

    /**
     * Drafts only.
     *
     * @param  array{title: array{ar: string, en: string}, body: array{ar: string, en: string}, app: Audience, city_id: ?int}  $data
     */
    public function update(BroadcastCampaign $campaign, User $admin, array $data): BroadcastCampaign
    {
        return DB::transaction(function () use ($campaign, $admin, $data) {
            $campaign = $this->lock($campaign);
            if ($campaign->status !== BroadcastStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $before = ContentVisibility::snapshot($campaign, self::FIELDS);
            $campaign->update($data);
            [$old, $new] = ContentVisibility::diff($before, ContentVisibility::snapshot($campaign, self::FIELDS));
            if ($new !== []) {
                $this->audit->log('broadcast_updated', $admin, $campaign, null, $old, $new);
            }

            return $campaign;
        });
    }

    /**
     * Send now. The staff member confirms the count she saw; a different live count is `409 AUDIENCE_CHANGED`.
     */
    public function send(BroadcastCampaign $campaign, User $admin, int $expectedCount): BroadcastCampaign
    {
        return DB::transaction(function () use ($campaign, $admin, $expectedCount) {
            $campaign = $this->lock($campaign);
            $this->guardMove($campaign, BroadcastStatus::Sending);
            $count = $this->audience->count($campaign);
            if ($count === 0) {
                throw new ApiException(ErrorCode::NoRecipients);
            }
            if ($count !== $expectedCount) {
                throw new ApiException(ErrorCode::AudienceChanged);
            }

            return $this->start($campaign, $admin, $count);
        });
    }

    /**
     * Send later: `broadcasts:dispatch-due` starts it at `scheduled_at`.
     */
    public function schedule(BroadcastCampaign $campaign, User $admin, CarbonInterface $at): BroadcastCampaign
    {
        return DB::transaction(function () use ($campaign, $admin, $at) {
            $campaign = $this->lock($campaign);
            if ($campaign->status !== BroadcastStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $this->guardTranslations($campaign);
            $campaign->update(['status' => BroadcastStatus::Scheduled, 'scheduled_at' => $at, 'sent_by' => $admin->id]);
            $this->audit->log('broadcast_scheduled', $admin, $campaign, null, ['status' => BroadcastStatus::Draft->value],
                ['status' => BroadcastStatus::Scheduled->value, 'scheduled_at' => $at->toIso8601String()]);

            return $campaign;
        });
    }

    /**
     * Cancels a draft or a scheduled campaign, with a reason. A campaign already sending cannot be stopped.
     */
    public function cancel(BroadcastCampaign $campaign, User $admin, string $reason): BroadcastCampaign
    {
        return DB::transaction(function () use ($campaign, $admin, $reason) {
            $campaign = $this->lock($campaign);
            if (! $campaign->status->canMoveTo(BroadcastStatus::Cancelled)) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $from = $campaign->status;
            $campaign->update(['status' => BroadcastStatus::Cancelled, 'cancel_reason' => $reason, 'cancelled_by' => $admin->id]);
            $this->audit->log('broadcast_cancelled', $admin, $campaign, $reason, ['status' => $from->value], ['status' => BroadcastStatus::Cancelled->value]);

            return $campaign;
        });
    }

    /**
     * Starts every scheduled campaign that is due (`broadcasts:dispatch-due`). Returns how many started.
     */
    public function startDue(): int
    {
        $started = 0;
        $ids = BroadcastCampaign::query()->where('status', BroadcastStatus::Scheduled)->where('scheduled_at', '<=', now())->pluck('id');
        foreach ($ids as $id) {
            $started += DB::transaction(function () use ($id) {
                $campaign = BroadcastCampaign::query()->whereKey($id)->lockForUpdate()->first();
                if ($campaign === null || $campaign->status !== BroadcastStatus::Scheduled || $campaign->scheduled_at?->isFuture()) {
                    return 0; // started, cancelled or moved by someone else meanwhile
                }
                $actor = $campaign->sent_by ? User::query()->find($campaign->sent_by) : null;
                $this->start($campaign, $actor, $this->audience->count($campaign));

                return 1;
            });
        }

        return $started;
    }

    /**
     * Called by the job when every recipient was handed to the Notifier.
     */
    public function finish(BroadcastCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            $campaign = $this->lock($campaign);
            if ($campaign->status === BroadcastStatus::Sending) {
                $campaign->update(['status' => BroadcastStatus::Sent, 'finished_at' => now()]);
            }
        });
    }

    private function start(BroadcastCampaign $campaign, ?User $actor, int $count): BroadcastCampaign
    {
        $this->guardTranslations($campaign);
        $from = $campaign->status;
        $campaign->update([
            'status' => BroadcastStatus::Sending,
            'recipients_count' => $count,
            'sent_at' => now(),
            'sent_by' => $actor?->id ?? $campaign->sent_by,
        ]);
        $this->audit->log('broadcast_sent', $actor, $campaign, null, ['status' => $from->value],
            ['status' => BroadcastStatus::Sending->value, 'recipients_count' => $count]);

        $id = $campaign->id;
        DB::afterCommit(fn () => SendBroadcastCampaign::dispatch($id));

        return $campaign;
    }

    private function guardMove(BroadcastCampaign $campaign, BroadcastStatus $to): void
    {
        if (! $campaign->status->canMoveTo($to)) {
            throw new ApiException(ErrorCode::InvalidState);
        }
    }

    private function guardTranslations(BroadcastCampaign $campaign): void
    {
        if (ContentTranslations::missing($campaign, ['title', 'body']) !== []) {
            throw new ApiException(ErrorCode::TranslationMissing);
        }
    }

    private function lock(BroadcastCampaign $campaign): BroadcastCampaign
    {
        return BroadcastCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
    }
}
