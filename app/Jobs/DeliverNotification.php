<?php

namespace App\Jobs;

use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores the notification row (unless push only, D142) and queues one push per active device (D139). The row is
 * unique per recipient and `dedupe_key`, so a re-run stores nothing and pushes nothing. Never throws.
 */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly int $userId,
        public readonly NotificationEvent $event,
        public readonly array $params,
        public readonly NotificationTargetType $targetType,
        public readonly ?int $targetId,
        public readonly string $dedupeKey,
    ) {}

    public function handle(NotificationPreferenceService $preferences): void
    {
        try {
            $user = User::query()->find($this->userId);
            if ($user === null) {
                return;
            }

            $notificationId = null;
            if ($this->event->storesRow()) {
                $notificationId = (string) Str::uuid7(); // time-ordered: ties on created_at keep insertion order
                $stored = DB::table('notifications')->insertOrIgnore([
                    'id' => $notificationId,
                    'type' => $this->event->value,
                    'notifiable_type' => $user->getMorphClass(),
                    'notifiable_id' => $user->id,
                    'data' => json_encode([
                        'event' => $this->event->value,
                        'params' => $this->params,
                        'target' => ['type' => $this->targetType->value, 'id' => $this->targetId],
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'dedupe_key' => $this->dedupeKey,
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($stored === 0) {
                    return; // already delivered
                }
            } elseif (! Cache::add('notifications:push:'.$user->id.':'.$this->dedupeKey, 1, now()->addDay())) {
                return;
            }

            $key = $this->event->preferenceKey();
            if ($key !== null && ! $preferences->isEnabled($user, $key)) {
                return; // D135: the row is kept, the push is switched off
            }

            UserDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->whereNotNull('fcm_token')
                ->orderBy('id')->get(['id', 'fcm_token'])
                ->each(fn (UserDevice $device) => SendPushNotification::dispatch(
                    $device->id, $device->fcm_token, $user->id, $this->event, $this->params,
                    $this->targetType, $this->targetId, $notificationId, $this->collapseKey(),
                ));
        } catch (Throwable $e) {
            Log::error('[notifications] delivery failed', ['event' => $this->event->value, 'user_id' => $this->userId, 'error' => $e->getMessage()]);
        }
    }

    private function collapseKey(): string
    {
        return $this->event === NotificationEvent::ChatMessage
            ? 'conversation-'.$this->targetId
            : substr($this->event->value.'-'.md5($this->dedupeKey), 0, 64);
    }
}
