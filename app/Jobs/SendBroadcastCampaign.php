<?php

namespace App\Jobs;

use App\Enums\BroadcastStatus;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Models\BroadcastCampaign;
use App\Models\User;
use App\Services\Content\BroadcastAudience;
use App\Services\Content\BroadcastCampaigns;
use App\Services\Notifications\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands every recipient of a sending campaign to the Slice 8 Notifier (D306): event E40 `broadcast`, target
 * `broadcast`, dedupe `broadcast:{id}` (a retried job notifies nobody twice). The push respects `promotions` (D135).
 */
class SendBroadcastCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    private const CHUNK = 500;

    public function __construct(public readonly int $campaignId) {}

    public function handle(BroadcastAudience $audience, Notifier $notifier, BroadcastCampaigns $campaigns): void
    {
        $campaign = BroadcastCampaign::query()->find($this->campaignId);
        if ($campaign === null || $campaign->status !== BroadcastStatus::Sending) {
            return;
        }

        $params = ['title' => $campaign->getTranslations('title'), 'body' => $campaign->getTranslations('body')];
        $dedupe = 'broadcast:'.$campaign->id;

        $audience->query($campaign->app, $campaign->city_id)
            ->select('users.id')
            ->chunkById(self::CHUNK, function ($users) use ($notifier, $params, $campaign, $dedupe) {
                foreach ($users as $user) {
                    /** @var User $user */
                    $notifier->send($user->id, NotificationEvent::Broadcast, $params, NotificationTargetType::Broadcast, $campaign->id, $dedupe);
                }
            }, 'users.id', 'id');

        $campaigns->finish($campaign);
    }

    /**
     * Every attempt failed: the campaign stays `sending` and `broadcasts:dispatch-due` retries it after
     * BroadcastCampaigns::STALE_SENDING_MINUTES (review M3).
     */
    public function failed(?Throwable $e): void
    {
        Log::error('[broadcasts] delivery job failed; it will be retried by broadcasts:dispatch-due', [
            'campaign_id' => $this->campaignId,
            'error' => $e?->getMessage(),
        ]);
    }
}
